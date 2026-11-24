// Public deployment configuration. Never put credentials, tokens or license keys here.
window.HWADMIN_CONFIG = {
  title: 'HwAdmin',
  apiBase: '/adminapi',
  tokenHeader: 'X-Token',
  storageNamespace: 'hwadmin', // Use a unique value for multiple frontends on one origin.
}
