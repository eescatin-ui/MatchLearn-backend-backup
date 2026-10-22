import { createApp } from 'vue';
import axios from 'axios';
import AdminApp from './admin/AdminApp.vue';

const root = document.getElementById('admin-app');

if (root) {
    axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
    axios.defaults.headers.common['X-CSRF-TOKEN'] =
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    createApp(AdminApp, {
        adminName: root.dataset.adminName ?? 'Administrator',
        adminEmail: root.dataset.adminEmail ?? '',
    }).mount(root);
}
