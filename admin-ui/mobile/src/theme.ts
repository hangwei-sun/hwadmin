import type { ConfigProviderThemeVars } from '@wot-ui/ui'

// Inline provider variables take precedence over lazily loaded Wot light themes.
// Keep palette values in style/theme.scss; portals receive these mappings too.
export const themeVars: ConfigProviderThemeVars = {
  primary1: 'var(--hwadmin-primary-1)',
  primary2: 'var(--hwadmin-primary-2)',
  primary3: 'var(--hwadmin-primary-3)',
  primary4: 'var(--hwadmin-primary-4)',
  primary5: 'var(--hwadmin-primary-5)',
  primary6: 'var(--hwadmin-primary-6)',
  primary7: 'var(--hwadmin-primary-7)',
  primary8: 'var(--hwadmin-primary-8)',
  primary9: 'var(--hwadmin-primary-9)',
  primary10: 'var(--hwadmin-primary-10)',
}
