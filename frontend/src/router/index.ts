import { createRouter, createWebHistory } from 'vue-router'
import PosView from '@/views/PosView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'pos',
      component: PosView,
    },
  ],
})

export default router
