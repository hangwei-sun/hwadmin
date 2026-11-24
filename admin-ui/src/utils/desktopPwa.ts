import { ref } from 'vue'

/** Existing update UI stays inert inside an installed desktop application. */
export const useRegisterSW = (_options?: unknown) => ({
  offlineReady: ref(false),
  needRefresh: ref(false),
  updateServiceWorker: async (_reloadPage?: boolean) => {},
})
