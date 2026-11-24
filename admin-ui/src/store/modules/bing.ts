export const useBingStore = defineStore('bing', {
  state: (): BingModuleType => ({ backgroundList: [] }),
  getters: { getBackgroundList: (state) => state.backgroundList },
  actions: {
    async setBackgroundList() {
      this.backgroundList = []
    },
  },
})
