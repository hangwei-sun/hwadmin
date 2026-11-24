import banner from 'vite-plugin-banner'

export const createBanner = () => {
  return [
    banner(` build: HwAdmin \n time: ${process.env.VITE_APP_UPDATE_TIME || ''} \n`),
  ] as any
}
