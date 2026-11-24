// Copy chosen values into the project's public/hwadmin.config.js before building.
// The deployed hwadmin.config.js is loaded before the application. No secrets here.
window.HWADMIN_CONFIG = {
  title: 'HwAdmin',
  apiBase: '/adminapi',
  tokenHeader: 'X-Token',
  storageNamespace: 'customer-a',
}
