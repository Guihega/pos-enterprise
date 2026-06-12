import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '@/views/LoginView.vue'
import PosView from '@/views/PosView.vue'
import ProductsView from '@/views/ProductsView.vue'
import CustomersView from '@/views/CustomersView.vue'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { requiresAuth: false },
    },
    {
      path: '/',
      name: 'pos',
      component: PosView,
      meta: { requiresAuth: true },
    },
    {
      path: '/catalogo',
      name: 'catalogo',
      component: ProductsView,
      meta: { requiresAuth: true },
    },
    {
      path: '/clientes',
      name: 'clientes',
      component: CustomersView,
      meta: { requiresAuth: true },
    },
  ],
})

router.beforeEach((to) => {
  const auth = useAuthStore()

  // Ruta protegida sin sesion: a /login
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' }
  }

  // Ya autenticado tratando de entrar a /login: a /
  if (to.name === 'login' && auth.isAuthenticated) {
    return { name: 'pos' }
  }

  return true
})

export default router
