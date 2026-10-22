<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';

type Section = 'overview' | 'users' | 'groups' | 'sessions' | 'reports' | 'matching';
type UserStatus = 'all' | 'active' | 'suspended';
type ReportStatus = 'all' | 'pending' | 'reviewed' | 'dismissed';
type SessionStatus = 'all' | 'pending' | 'confirmed' | 'declined' | 'cancelled' | 'completed';

interface Analytics {
    users: number;
    active_users: number;
    suspended_users: number;
    pending_reports: number;
    completed_sessions: number;
    match_records: number;
    accepted_buddy_connections: number;
    buddy_request_acceptance_rate: number;
}

interface ManagedUser {
    id: number;
    name: string;
    email: string;
    profile_photo_url: string | null;
    program: string | null;
    is_suspended: boolean;
    is_admin: boolean;
    created_at: string;
}

interface Report {
    id: number;
    reporter_user_id: number;
    reported_user_id: number;
    reason: string;
    reasons?: string[] | null;
    details: string;
    status: Exclude<ReportStatus, 'all'>;
    created_at: string;
    reporter: { id: number; name: string; email: string } | null;
    reported_user: { id: number; name: string; email: string } | null;
}

interface StudyGroup {
    id: number;
    name: string;
    course: string;
    schedule: string | null;
    description: string | null;
    creator: string | null;
    members_count: number;
    created_at: string;
}

interface StudySession {
    id: number;
    topic: string;
    host: string | null;
    participant: string | null;
    group: string | null;
    starts_at: string;
    ends_at: string | null;
    location: string;
    status: Exclude<SessionStatus, 'all'>;
}

interface StudyGroupForm {
    name: string;
    course: string;
    schedule: string;
    description: string;
}

interface StudySessionForm {
    topic: string;
    starts_at: string;
    ends_at: string;
    location: string;
    status: Exclude<SessionStatus, 'all'>;
}

interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
}

interface MatchingWeights {
    courses: number;
    academic_complementarity: number;
    program: number;
    learning_style: number;
    schedule: number;
    year_level: number;
    study_mode: number;
}

const props = defineProps<{ adminName: string; adminEmail: string }>();
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
const activeSection = ref<Section>('overview');
const analytics = ref<Analytics | null>(null);
const users = ref<Paginator<ManagedUser> | null>(null);
const groups = ref<Paginator<StudyGroup> | null>(null);
const sessions = ref<Paginator<StudySession> | null>(null);
const reports = ref<Paginator<Report> | null>(null);
const editingGroup = ref<StudyGroup | null>(null);
const editingSession = ref<StudySession | null>(null);
const groupForm = reactive<StudyGroupForm>({ name: '', course: '', schedule: '', description: '' });
const sessionForm = reactive<StudySessionForm>({
    topic: '',
    starts_at: '',
    ends_at: '',
    location: '',
    status: 'pending',
});
const userSearch = ref('');
const groupSearch = ref('');
const sessionSearch = ref('');
const sessionStatus = ref<SessionStatus>('all');
const userStatus = ref<UserStatus>('all');
const reportStatus = ref<ReportStatus>('pending');
const userPage = ref(1);
const groupPage = ref(1);
const sessionPage = ref(1);
const reportPage = ref(1);
const weights = reactive<MatchingWeights>({
    courses: 25,
    academic_complementarity: 25,
    program: 20,
    learning_style: 10,
    schedule: 10,
    year_level: 5,
    study_mode: 5,
});
const weightsLoaded = ref(false);
const loading = ref(false);
const savingWeights = ref(false);
const savingRecord = ref(false);
const pageError = ref('');
const notice = ref('');
const mobileMenuOpen = ref(false);
let noticeTimer: ReturnType<typeof setTimeout> | undefined;
let userSearchTimer: ReturnType<typeof setTimeout> | undefined;

const weightTotal = computed(() => Object.values(weights).reduce((sum, value) => sum + Number(value || 0), 0));
const pageTitle = computed(() => ({
    overview: 'Overview',
    users: 'Student accounts',
    groups: 'Study groups',
    sessions: 'Study sessions',
    reports: 'Safety reports',
    matching: 'Match settings',
}[activeSection.value]));

const metricCards = computed(() => {
    if (!analytics.value) return [];
    return [
        { label: 'Total students', value: analytics.value.users.toLocaleString(), caption: 'Registered accounts', icon: '01', tone: 'blue' },
        { label: 'Active students', value: analytics.value.active_users.toLocaleString(), caption: 'Accounts in good standing', icon: '02', tone: 'green' },
        { label: 'Needs review', value: analytics.value.pending_reports.toLocaleString(), caption: 'Pending safety reports', icon: '03', tone: 'orange' },
        { label: 'Study sessions', value: analytics.value.completed_sessions.toLocaleString(), caption: 'Completed sessions', icon: '04', tone: 'purple' },
    ];
});

const weightFields: { key: keyof MatchingWeights; label: string; description: string }[] = [
    { key: 'courses', label: 'Shared courses', description: 'Students taking the same course codes' },
    { key: 'academic_complementarity', label: 'Academic strengths', description: 'How well strengths complement learning needs' },
    { key: 'program', label: 'Program and department', description: 'Same academic program or department' },
    { key: 'learning_style', label: 'Learning style', description: 'Compatibility between preferred learning styles' },
    { key: 'schedule', label: 'Schedule overlap', description: 'Common available study times' },
    { key: 'year_level', label: 'Year level', description: 'Closeness of students’ year levels' },
    { key: 'study_mode', label: 'Study mode', description: 'Preference for online or in-person study' },
];

function messageFrom(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const message = error.response?.data?.message;
        if (typeof message === 'string') return message;
        const errors = error.response?.data?.errors;
        if (errors && typeof errors === 'object') {
            const firstError = Object.values(errors).flat().find((item) => typeof item === 'string');
            if (typeof firstError === 'string') return firstError;
        }
    }
    return 'Something went wrong. Please try again.';
}

function showNotice(text: string): void {
    notice.value = text;
    if (noticeTimer) clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => { notice.value = ''; }, 4000);
}

async function loadAnalytics(): Promise<void> {
    const response = await axios.get<Analytics>('/admin/api/analytics');
    analytics.value = response.data;
}

async function loadUsers(): Promise<void> {
    const response = await axios.get<Paginator<ManagedUser>>('/admin/api/users', {
        params: { search: userSearch.value || undefined, status: userStatus.value, page: userPage.value },
    });
    users.value = response.data;
}

async function loadGroups(): Promise<void> {
    const response = await axios.get<Paginator<StudyGroup>>('/admin/api/groups', {
        params: { search: groupSearch.value || undefined, page: groupPage.value },
    });
    groups.value = response.data;
}

async function loadSessions(): Promise<void> {
    const response = await axios.get<Paginator<StudySession>>('/admin/api/study-sessions', {
        params: {
            search: sessionSearch.value || undefined,
            status: sessionStatus.value,
            page: sessionPage.value,
        },
    });
    sessions.value = response.data;
}

async function loadReports(): Promise<void> {
    const response = await axios.get<Paginator<Report>>('/admin/api/reports', {
        params: { status: reportStatus.value, page: reportPage.value },
    });
    reports.value = response.data;
}

async function loadWeights(): Promise<void> {
    const response = await axios.get<{ weights: MatchingWeights }>('/admin/api/matching-weights');
    Object.assign(weights, response.data.weights);
    weightsLoaded.value = true;
}

async function loadSection(section: Section = activeSection.value): Promise<void> {
    loading.value = true;
    pageError.value = '';
    try {
        if (section === 'overview') await loadAnalytics();
        if (section === 'users') await loadUsers();
        if (section === 'groups') await loadGroups();
        if (section === 'sessions') await loadSessions();
        if (section === 'reports') await loadReports();
        if (section === 'matching') await loadWeights();
    } catch (error) {
        pageError.value = messageFrom(error);
    } finally {
        loading.value = false;
    }
}

function navigate(section: Section): void {
    activeSection.value = section;
    mobileMenuOpen.value = false;
    void loadSection(section);
}

async function setUserSuspended(user: ManagedUser): Promise<void> {
    const nextStatus = !user.is_suspended;
    const action = nextStatus ? 'suspend' : 'restore';
    if (!window.confirm(`Are you sure you want to ${action} ${user.name}’s account?`)) return;

    pageError.value = '';
    try {
        await axios.patch(`/admin/api/users/${user.id}/status`, { is_suspended: nextStatus });
        showNotice(nextStatus ? 'Student account suspended.' : 'Student account restored.');
        await Promise.all([loadUsers(), loadAnalytics()]);
    } catch (error) {
        pageError.value = messageFrom(error);
    }
}

async function updateReport(report: Report, status: 'reviewed' | 'dismissed'): Promise<void> {
    pageError.value = '';
    try {
        await axios.patch(`/admin/api/reports/${report.id}`, { status });
        showNotice(status === 'reviewed' ? 'Report marked as reviewed.' : 'Report dismissed.');
        await Promise.all([loadReports(), loadAnalytics()]);
    } catch (error) {
        pageError.value = messageFrom(error);
    }
}

async function saveWeights(): Promise<void> {
    if (weightTotal.value !== 100) return;
    savingWeights.value = true;
    pageError.value = '';
    try {
        const response = await axios.put<{ message: string; weights: MatchingWeights }>(
            '/admin/api/matching-weights',
            { weights: { ...weights } },
        );
        Object.assign(weights, response.data.weights);
        showNotice(response.data.message);
    } catch (error) {
        pageError.value = messageFrom(error);
    } finally {
        savingWeights.value = false;
    }
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value));
}

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function initials(name: string): string {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function prettyReason(reason: string): string {
    return reason.replaceAll('_', ' ');
}

function reportReasons(report: Report): string[] {
    return report.reasons?.length ? report.reasons : [report.reason];
}

function toLocalDateTimeInput(value: string | null): string {
    if (!value) return '';
    const date = new Date(value);
    const pad = (part: number) => String(part).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function fromLocalDateTimeInput(value: string): string | null {
    return value ? new Date(value).toISOString() : null;
}

function editGroup(group: StudyGroup): void {
    editingSession.value = null;
    editingGroup.value = group;
    Object.assign(groupForm, {
        name: group.name,
        course: group.course,
        schedule: group.schedule || '',
        description: group.description || '',
    });
}

function editSession(session: StudySession): void {
    editingGroup.value = null;
    editingSession.value = session;
    Object.assign(sessionForm, {
        topic: session.topic,
        starts_at: toLocalDateTimeInput(session.starts_at),
        ends_at: toLocalDateTimeInput(session.ends_at),
        location: session.location,
        status: session.status,
    });
}

function closeEditor(): void {
    if (savingRecord.value) return;
    editingGroup.value = null;
    editingSession.value = null;
}

async function saveGroup(): Promise<void> {
    if (!editingGroup.value) return;
    savingRecord.value = true;
    pageError.value = '';
    try {
        await axios.put(`/admin/api/groups/${editingGroup.value.id}`, {
            name: groupForm.name,
            course: groupForm.course,
            schedule: groupForm.schedule || null,
            description: groupForm.description || null,
        });
        editingGroup.value = null;
        showNotice('Study group updated.');
        await loadGroups();
    } catch (error) {
        pageError.value = messageFrom(error);
    } finally {
        savingRecord.value = false;
    }
}

async function saveSession(): Promise<void> {
    if (!editingSession.value) return;
    savingRecord.value = true;
    pageError.value = '';
    try {
        await axios.put(`/admin/api/study-sessions/${editingSession.value.id}`, {
            topic: sessionForm.topic,
            starts_at: fromLocalDateTimeInput(sessionForm.starts_at),
            ends_at: fromLocalDateTimeInput(sessionForm.ends_at),
            location: sessionForm.location,
            status: sessionForm.status,
        });
        editingSession.value = null;
        showNotice('Study session updated.');
        await loadSessions();
    } catch (error) {
        pageError.value = messageFrom(error);
    } finally {
        savingRecord.value = false;
    }
}

async function deleteGroup(group: StudyGroup): Promise<void> {
    if (!window.confirm(`Delete “${group.name}”? Any study sessions linked to this group will also be deleted.`)) return;
    pageError.value = '';
    try {
        await axios.delete(`/admin/api/groups/${group.id}`);
        showNotice('Study group deleted.');
        await loadGroups();
        if (groups.value && groups.value.data.length === 0 && groupPage.value > 1) {
            groupPage.value -= 1;
            await loadGroups();
        }
    } catch (error) {
        pageError.value = messageFrom(error);
    }
}

async function deleteSession(session: StudySession): Promise<void> {
    if (!window.confirm(`Delete the study session “${session.topic}”? This cannot be undone.`)) return;
    pageError.value = '';
    try {
        await axios.delete(`/admin/api/study-sessions/${session.id}`);
        showNotice('Study session deleted.');
        await loadSessions();
        if (sessions.value && sessions.value.data.length === 0 && sessionPage.value > 1) {
            sessionPage.value -= 1;
            await loadSessions();
        }
    } catch (error) {
        pageError.value = messageFrom(error);
    }
}

watch([userStatus], () => {
    userPage.value = 1;
    if (activeSection.value === 'users') void loadUsers().catch((error) => { pageError.value = messageFrom(error); });
});

watch(userSearch, () => {
    userPage.value = 1;
    if (userSearchTimer) clearTimeout(userSearchTimer);
    userSearchTimer = setTimeout(() => {
        if (activeSection.value === 'users') {
            void loadUsers().catch((error) => { pageError.value = messageFrom(error); });
        }
    }, 250);
});

watch(groupSearch, () => {
    groupPage.value = 1;
    if (userSearchTimer) clearTimeout(userSearchTimer);
    userSearchTimer = setTimeout(() => {
        if (activeSection.value === 'groups') {
            void loadGroups().catch((error) => { pageError.value = messageFrom(error); });
        }
    }, 250);
});

watch(sessionSearch, () => {
    sessionPage.value = 1;
    if (userSearchTimer) clearTimeout(userSearchTimer);
    userSearchTimer = setTimeout(() => {
        if (activeSection.value === 'sessions') {
            void loadSessions().catch((error) => { pageError.value = messageFrom(error); });
        }
    }, 250);
});

watch(sessionStatus, () => {
    sessionPage.value = 1;
    if (activeSection.value === 'sessions') void loadSessions().catch((error) => { pageError.value = messageFrom(error); });
});

watch(reportStatus, () => {
    reportPage.value = 1;
    if (activeSection.value === 'reports') void loadReports().catch((error) => { pageError.value = messageFrom(error); });
});

onMounted(() => { void loadSection('overview'); });
</script>

<template>
    <div class="admin-shell">
        <aside class="sidebar" :class="{ 'sidebar-open': mobileMenuOpen }">
            <a class="brand-lockup sidebar-brand" href="/" aria-label="MatchLearn home">
                <span class="brand-mark">M</span>
                <span>Match<span class="brand-accent">Learn</span></span>
            </a>
            <div class="sidebar-label">WORKSPACE</div>
            <nav class="side-nav" aria-label="Admin navigation">
                <button :class="{ active: activeSection === 'overview' }" @click="navigate('overview')"><span class="nav-icon">▦</span> Overview</button>
                <button :class="{ active: activeSection === 'users' }" @click="navigate('users')"><span class="nav-icon">♙</span> Students</button>
                <button :class="{ active: activeSection === 'groups' }" @click="navigate('groups')"><span class="nav-icon">◫</span> Study groups</button>
                <button :class="{ active: activeSection === 'sessions' }" @click="navigate('sessions')"><span class="nav-icon">◷</span> Study sessions</button>
                <button :class="{ active: activeSection === 'reports' }" @click="navigate('reports')"><span class="nav-icon">⚑</span> Safety reports <span v-if="analytics?.pending_reports" class="nav-count">{{ analytics.pending_reports }}</span></button>
                <button :class="{ active: activeSection === 'matching' }" @click="navigate('matching')"><span class="nav-icon">⌘</span> Match settings</button>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-note"><span class="status-dot"></span><span><strong>System online</strong><small>MatchLearn admin</small></span></div>
                <form method="POST" action="/admin/logout">
                    <input type="hidden" name="_token" :value="csrfToken">
                    <button class="signout-button" type="submit"><span class="nav-icon">↪</span> Sign out</button>
                </form>
            </div>
        </aside>

        <div v-if="mobileMenuOpen" class="sidebar-scrim" @click="mobileMenuOpen = false"></div>

        <main class="main-panel">
            <header class="topbar">
                <button class="mobile-menu-button" aria-label="Open navigation menu" @click="mobileMenuOpen = !mobileMenuOpen">☰</button>
                <div class="breadcrumbs"><span>Admin</span><span class="crumb-divider">/</span><strong>{{ pageTitle }}</strong></div>
                <div class="admin-account">
                    <span class="admin-avatar">{{ initials(adminName) }}</span>
                    <span class="admin-account-copy"><strong>{{ adminName }}</strong><small>{{ adminEmail || 'Administrator' }}</small></span>
                </div>
            </header>

            <div class="page-content">
                <section class="page-heading">
                    <div>
                        <p class="eyebrow">MATCHLEARN CONTROL CENTER</p>
                        <h1>{{ pageTitle }}</h1>
                        <p class="page-subtitle">
                            <template v-if="activeSection === 'overview'">A clear picture of your learning community.</template>
                            <template v-else-if="activeSection === 'users'">Find and manage student accounts.</template>
                            <template v-else-if="activeSection === 'groups'">Browse study groups created by students.</template>
                            <template v-else-if="activeSection === 'sessions'">Browse scheduled study sessions and their status.</template>
                            <template v-else-if="activeSection === 'reports'">Review reports and keep the community safe.</template>
                            <template v-else>Choose what matters most when recommending study partners.</template>
                        </p>
                    </div>
                    <button v-if="activeSection === 'overview'" class="button button-outline refresh-button" :disabled="loading" @click="loadSection()">
                        <span aria-hidden="true">↻</span> Refresh
                    </button>
                </section>

                <div v-if="pageError" class="alert alert-error" role="alert">
                    <span>{{ pageError }}</span>
                    <button aria-label="Dismiss error" @click="pageError = ''">×</button>
                </div>
                <div v-if="notice" class="alert alert-success" role="status">
                    <span>{{ notice }}</span>
                    <button aria-label="Dismiss notification" @click="notice = ''">×</button>
                </div>

                <section v-if="activeSection === 'overview'" aria-label="Community overview">
                    <div v-if="loading && !analytics" class="loading-state">Loading community overview…</div>
                    <template v-else-if="analytics">
                        <div class="metric-grid">
                            <article v-for="metric in metricCards" :key="metric.label" class="metric-card">
                                <div class="metric-topline">
                                    <span>{{ metric.label }}</span>
                                    <span class="metric-icon" :class="`tone-${metric.tone}`">{{ metric.icon }}</span>
                                </div>
                                <strong class="metric-value">{{ metric.value }}</strong>
                                <span class="metric-caption">{{ metric.caption }}</span>
                            </article>
                        </div>
                        <div class="overview-grid">
                            <article class="panel community-panel">
                                <div class="panel-heading">
                                    <div><p class="eyebrow">COMMUNITY HEALTH</p><h2>Students at a glance</h2></div>
                                    <span class="panel-icon">♙</span>
                                </div>
                                <div class="community-summary">
                                    <div class="summary-number">{{ analytics.users.toLocaleString() }}</div>
                                    <div class="summary-copy"><strong>Total accounts</strong><span>Student community</span></div>
                                </div>
                                <div class="bar-track"><span class="bar-fill" :style="{ width: `${analytics.users ? (analytics.active_users / analytics.users) * 100 : 0}%` }"></span></div>
                                <div class="bar-legend">
                                    <span><i class="legend-dot green-dot"></i> Active <strong>{{ analytics.active_users }}</strong></span>
                                    <span><i class="legend-dot muted-dot"></i> Suspended <strong>{{ analytics.suspended_users }}</strong></span>
                                </div>
                            </article>
                            <article class="panel activity-panel">
                                <div class="panel-heading">
                                    <div><p class="eyebrow">PLATFORM ACTIVITY</p><h2>Learning connections</h2></div>
                                    <span class="panel-icon">⌘</span>
                                </div>
                                <div class="activity-row"><span class="activity-bullet blue-bullet">↗</span><span><strong>{{ analytics.match_records.toLocaleString() }}</strong><small>Match recommendations</small></span></div>
                                <div class="activity-row"><span class="activity-bullet purple-bullet">♡</span><span><strong>{{ analytics.accepted_buddy_connections.toLocaleString() }}</strong><small>Accepted buddy connections</small></span></div>
                                <div class="activity-row"><span class="activity-bullet orange-bullet">✓</span><span><strong>{{ analytics.buddy_request_acceptance_rate }}%</strong><small>Buddy request acceptance</small></span></div>
                            </article>
                            <article class="panel attention-panel">
                                <div class="attention-icon">!</div>
                                <div class="attention-copy">
                                    <p class="eyebrow">COMMUNITY CARE</p>
                                    <h2>{{ analytics.pending_reports }} {{ analytics.pending_reports === 1 ? 'report needs' : 'reports need' }} your attention</h2>
                                    <p>Review new reports promptly to support a welcoming study community.</p>
                                </div>
                                <button class="button button-primary" @click="navigate('reports')">Review reports <span aria-hidden="true">→</span></button>
                            </article>
                        </div>
                    </template>
                </section>

                <section v-else-if="activeSection === 'users'" class="panel data-panel" aria-label="Student accounts">
                    <div class="table-toolbar">
                        <div><h2>All students</h2><p>Account access and enrollment details</p></div>
                        <div class="table-filters">
                            <label class="search-box"><span aria-hidden="true">⌕</span><input v-model="userSearch" type="search" placeholder="Search name or email" aria-label="Search students"></label>
                            <select v-model="userStatus" aria-label="Filter student status">
                                <option value="all">All accounts</option><option value="active">Active</option><option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                    <div v-if="loading && !users" class="loading-state">Loading student accounts…</div>
                    <div v-else-if="users?.data.length" class="table-scroll">
                        <table>
                            <thead><tr><th>STUDENT</th><th>PROGRAM</th><th>JOINED</th><th>STATUS</th><th class="action-column">ACTION</th></tr></thead>
                            <tbody>
                                <tr v-for="user in users.data" :key="user.id">
                                    <td><div class="person-cell"><img v-if="user.profile_photo_url" class="person-avatar person-avatar-image" :src="user.profile_photo_url" alt="" loading="lazy"><span v-else class="person-avatar">{{ initials(user.name) }}</span><span><strong>{{ user.name }}</strong><small>{{ user.email }}</small></span></div></td>
                                    <td>{{ user.program || '—' }}</td>
                                    <td>{{ formatDate(user.created_at) }}</td>
                                    <td><span class="status-pill" :class="user.is_suspended ? 'status-suspended' : 'status-active'"><i></i>{{ user.is_suspended ? 'Suspended' : 'Active' }}</span></td>
                                    <td class="action-column"><button class="table-action" :class="{ 'restore-action': user.is_suspended }" :disabled="user.is_admin" :title="user.is_admin ? 'Administrator accounts cannot be suspended here' : ''" @click="setUserSuspended(user)">{{ user.is_suspended ? 'Restore' : 'Suspend' }}</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="empty-state"><span>⌕</span><strong>No students found</strong><p>Try another search or change the account filter.</p></div>
                    <div v-if="users && users.last_page > 1" class="pagination">
                        <span>{{ users.total }} student accounts</span>
                        <div><button :disabled="userPage <= 1 || loading" @click="userPage--; loadUsers()">← Previous</button><span>Page {{ users.current_page }} of {{ users.last_page }}</span><button :disabled="userPage >= users.last_page || loading" @click="userPage++; loadUsers()">Next →</button></div>
                    </div>
                </section>

                <section v-else-if="activeSection === 'groups'" class="panel data-panel" aria-label="Study groups">
                    <div class="table-toolbar">
                        <div><h2>All study groups</h2><p>Groups, courses, creators, and membership</p></div>
                        <div class="table-filters">
                            <label class="search-box"><span aria-hidden="true">⌕</span><input v-model="groupSearch" type="search" placeholder="Search group, course, or creator" aria-label="Search study groups"></label>
                        </div>
                    </div>
                    <div v-if="loading && !groups" class="loading-state">Loading study groups…</div>
                    <div v-else-if="groups?.data.length" class="table-scroll">
                        <table>
                            <thead><tr><th>GROUP</th><th>COURSE</th><th>CREATED BY</th><th>MEMBERS</th><th>CREATED</th><th class="action-column">ACTIONS</th></tr></thead>
                            <tbody>
                                <tr v-for="group in groups.data" :key="group.id">
                                    <td><div class="person-cell"><span class="person-avatar group-avatar" aria-hidden="true">◫</span><span><strong>{{ group.name }}</strong><small>Group #{{ group.id }}</small></span></div></td>
                                    <td>{{ group.course }}</td>
                                    <td>{{ group.creator || 'Deleted account' }}</td>
                                    <td>{{ group.members_count }}</td>
                                    <td>{{ formatDate(group.created_at) }}</td>
                                    <td class="action-column"><button class="table-action edit-action" @click="editGroup(group)">Edit</button><button class="table-action" @click="deleteGroup(group)">Delete</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="empty-state"><span>◫</span><strong>No study groups found</strong><p>Try another search.</p></div>
                    <div v-if="groups && groups.last_page > 1" class="pagination">
                        <span>{{ groups.total }} groups</span>
                        <div><button :disabled="groupPage <= 1 || loading" @click="groupPage--; loadGroups()">← Previous</button><span>Page {{ groups.current_page }} of {{ groups.last_page }}</span><button :disabled="groupPage >= groups.last_page || loading" @click="groupPage++; loadGroups()">Next →</button></div>
                    </div>
                </section>

                <section v-else-if="activeSection === 'sessions'" class="panel data-panel" aria-label="Study sessions">
                    <div class="table-toolbar">
                        <div><h2>All study sessions</h2><p>Scheduled sessions, participants, and status</p></div>
                        <div class="table-filters">
                            <label class="search-box"><span aria-hidden="true">⌕</span><input v-model="sessionSearch" type="search" placeholder="Search topic, people, or location" aria-label="Search study sessions"></label>
                            <select v-model="sessionStatus" aria-label="Filter study session status">
                                <option value="all">All statuses</option><option value="pending">Pending</option><option value="confirmed">Confirmed</option><option value="declined">Declined</option><option value="cancelled">Cancelled</option><option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>
                    <div v-if="loading && !sessions" class="loading-state">Loading study sessions…</div>
                    <div v-else-if="sessions?.data.length" class="table-scroll">
                        <table>
                            <thead><tr><th>SESSION</th><th>PARTICIPANTS</th><th>DATE &amp; TIME</th><th>LOCATION</th><th>STATUS</th><th class="action-column">ACTIONS</th></tr></thead>
                            <tbody>
                                <tr v-for="session in sessions.data" :key="session.id">
                                    <td><div class="session-topic"><strong>{{ session.topic }}</strong><small>{{ session.group ? `Group · ${session.group}` : 'Buddy session' }} · #{{ session.id }}</small></div></td>
                                    <td>{{ session.host || 'Deleted account' }}<template v-if="session.participant"> · {{ session.participant }}</template></td>
                                    <td>{{ formatDateTime(session.starts_at) }}</td>
                                    <td>{{ session.location }}</td>
                                    <td><span class="status-pill" :class="`session-${session.status}`"><i></i>{{ prettyReason(session.status) }}</span></td>
                                    <td class="action-column"><button class="table-action edit-action" @click="editSession(session)">Edit</button><button class="table-action" @click="deleteSession(session)">Delete</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="empty-state"><span>◷</span><strong>No study sessions found</strong><p>Try another search or status filter.</p></div>
                    <div v-if="sessions && sessions.last_page > 1" class="pagination">
                        <span>{{ sessions.total }} sessions</span>
                        <div><button :disabled="sessionPage <= 1 || loading" @click="sessionPage--; loadSessions()">← Previous</button><span>Page {{ sessions.current_page }} of {{ sessions.last_page }}</span><button :disabled="sessionPage >= sessions.last_page || loading" @click="sessionPage++; loadSessions()">Next →</button></div>
                    </div>
                </section>

                <section v-else-if="activeSection === 'reports'" class="panel data-panel" aria-label="Safety reports">
                    <div class="table-toolbar">
                        <div><h2>Community reports</h2><p>Confidential reports submitted by students</p></div>
                        <div class="table-filters">
                            <select v-model="reportStatus" aria-label="Filter report status">
                                <option value="pending">Pending</option><option value="all">All reports</option><option value="reviewed">Reviewed</option><option value="dismissed">Dismissed</option>
                            </select>
                        </div>
                    </div>
                    <div v-if="loading && !reports" class="loading-state">Loading safety reports…</div>
                    <div v-else-if="reports?.data.length" class="report-list">
                        <article v-for="report in reports.data" :key="report.id" class="report-card">
                            <div class="report-card-top">
                                <div class="report-people">
                                    <span class="person-avatar report-avatar">{{ initials(report.reporter?.name || 'Student') }}</span>
                                    <span><small>REPORTED BY</small><strong>{{ report.reporter?.name || 'Deleted account' }}</strong></span>
                                    <span class="report-arrow">→</span>
                                    <span class="person-avatar report-avatar reported-avatar">{{ initials(report.reported_user?.name || 'Student') }}</span>
                                    <span><small>ABOUT</small><strong>{{ report.reported_user?.name || 'Deleted account' }}</strong></span>
                                </div>
                                <span class="status-pill" :class="`report-${report.status}`"><i></i>{{ prettyReason(report.status) }}</span>
                            </div>
                            <div class="report-details">
                                <span v-for="reason in reportReasons(report)" :key="reason" class="reason-chip">{{ prettyReason(reason) }}</span>
                                <p>{{ report.details || 'No additional details were provided.' }}</p>
                            </div>
                            <footer class="report-card-footer">
                                <span>Submitted {{ formatDate(report.created_at) }} · Report #{{ report.id }}</span>
                                <div v-if="report.status === 'pending'" class="report-actions">
                                    <button class="table-action" @click="updateReport(report, 'dismissed')">Dismiss</button>
                                    <button class="button button-primary button-small" @click="updateReport(report, 'reviewed')">Mark reviewed</button>
                                </div>
                            </footer>
                        </article>
                    </div>
                    <div v-else class="empty-state"><span>✓</span><strong>All clear</strong><p>No reports match this filter.</p></div>
                    <div v-if="reports && reports.last_page > 1" class="pagination">
                        <span>{{ reports.total }} reports</span>
                        <div><button :disabled="reportPage <= 1 || loading" @click="reportPage--; loadReports()">← Previous</button><span>Page {{ reports.current_page }} of {{ reports.last_page }}</span><button :disabled="reportPage >= reports.last_page || loading" @click="reportPage++; loadReports()">Next →</button></div>
                    </div>
                </section>

                <section v-else class="matching-layout" aria-label="Matching settings">
                    <article class="panel weights-panel">
                        <div class="panel-heading">
                            <div><p class="eyebrow">COMPATIBILITY MODEL</p><h2>Recommendation weights</h2><p class="panel-description">Adjust how much each factor contributes to a student's match score.</p></div>
                            <span class="panel-icon">⌘</span>
                        </div>
                        <div v-if="loading && !weightsLoaded" class="loading-state">Loading match settings…</div>
                        <template v-else>
                            <div v-for="field in weightFields" :key="field.key" class="weight-row">
                                <div class="weight-copy"><strong>{{ field.label }}</strong><span>{{ field.description }}</span></div>
                                <input v-model.number="weights[field.key]" type="number" min="0" max="100" step="1" :aria-label="`${field.label} weight`">
                                <span class="weight-unit">%</span>
                            </div>
                            <div class="weight-total" :class="{ 'total-invalid': weightTotal !== 100 }">
                                <div><span class="total-indicator" :class="{ 'indicator-invalid': weightTotal !== 100 }"></span><span>Total weight</span></div>
                                <strong>{{ weightTotal }}<small>%</small></strong>
                            </div>
                            <p v-if="weightTotal !== 100" class="validation-note">The weights must add up to exactly 100% before they can be saved.</p>
                            <div class="weight-footer">
                                <p>Updated weights are applied the next time a student refreshes their recommendations.</p>
                                <button class="button button-primary" :disabled="weightTotal !== 100 || savingWeights" @click="saveWeights">{{ savingWeights ? 'Saving…' : 'Save changes' }}</button>
                            </div>
                        </template>
                    </article>
                    <aside class="panel matching-info">
                        <div class="info-badge">i</div>
                        <h2>How matching works</h2>
                        <p>MatchLearn compares student profiles and study preferences to suggest helpful study partners.</p>
                        <ul><li>All factors combine into a score out of 100.</li><li>A higher weight gives that factor more influence.</li><li>Changes affect future match refreshes, not existing recommendations.</li></ul>
                    </aside>
                </section>
            </div>
        </main>

        <div v-if="editingGroup" class="modal-backdrop" @click.self="closeEditor">
            <section class="edit-modal" role="dialog" aria-modal="true" aria-labelledby="edit-group-title">
                <header class="edit-modal-header">
                    <div><p class="eyebrow">STUDY GROUP · #{{ editingGroup.id }}</p><h2 id="edit-group-title">Edit study group</h2></div>
                    <button class="modal-close" type="button" aria-label="Close edit dialog" :disabled="savingRecord" @click="closeEditor">×</button>
                </header>
                <form class="edit-form" @submit.prevent="saveGroup">
                    <label>Group name<input v-model.trim="groupForm.name" type="text" maxlength="255" required></label>
                    <label>Course<input v-model.trim="groupForm.course" type="text" maxlength="255" required></label>
                    <label>Schedule <span class="optional-label">Optional</span><input v-model.trim="groupForm.schedule" type="text" maxlength="255" placeholder="e.g. Tuesdays at 3 PM"></label>
                    <label>Description <span class="optional-label">Optional</span><textarea v-model.trim="groupForm.description" rows="4" maxlength="5000"></textarea></label>
                    <footer class="edit-modal-actions">
                        <button class="button button-outline" type="button" :disabled="savingRecord" @click="closeEditor">Cancel</button>
                        <button class="button button-primary" type="submit" :disabled="savingRecord">{{ savingRecord ? 'Saving…' : 'Save changes' }}</button>
                    </footer>
                </form>
            </section>
        </div>

        <div v-if="editingSession" class="modal-backdrop" @click.self="closeEditor">
            <section class="edit-modal" role="dialog" aria-modal="true" aria-labelledby="edit-session-title">
                <header class="edit-modal-header">
                    <div><p class="eyebrow">STUDY SESSION · #{{ editingSession.id }}</p><h2 id="edit-session-title">Edit study session</h2></div>
                    <button class="modal-close" type="button" aria-label="Close edit dialog" :disabled="savingRecord" @click="closeEditor">×</button>
                </header>
                <form class="edit-form" @submit.prevent="saveSession">
                    <label>Topic<input v-model.trim="sessionForm.topic" type="text" maxlength="255" required></label>
                    <div class="edit-form-row">
                        <label>Starts at<input v-model="sessionForm.starts_at" type="datetime-local" required></label>
                        <label>Ends at <span class="optional-label">Optional</span><input v-model="sessionForm.ends_at" type="datetime-local" :min="sessionForm.starts_at"></label>
                    </div>
                    <label>Location<input v-model.trim="sessionForm.location" type="text" maxlength="255" required></label>
                    <label>Status
                        <select v-model="sessionForm.status" required>
                            <option value="pending">Pending</option><option value="confirmed">Confirmed</option><option value="declined">Declined</option><option value="cancelled">Cancelled</option><option value="completed">Completed</option>
                        </select>
                    </label>
                    <footer class="edit-modal-actions">
                        <button class="button button-outline" type="button" :disabled="savingRecord" @click="closeEditor">Cancel</button>
                        <button class="button button-primary" type="submit" :disabled="savingRecord">{{ savingRecord ? 'Saving…' : 'Save changes' }}</button>
                    </footer>
                </form>
            </section>
        </div>
    </div>
</template>
