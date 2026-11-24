import { presetUni } from '@uni-helper/unocss-preset-uni'
import { presetLegacyCompat } from '@unocss/preset-legacy-compat'
import { defineConfig, transformerDirectives, transformerVariantGroup } from 'unocss'

export default defineConfig({
  presets: [presetUni({ attributify: false }), presetLegacyCompat({ commaStyleColorFunction: true, legacyColorSpace: true })],
  transformers: [transformerDirectives(), transformerVariantGroup()],
  theme: { colors: { primary: 'var(--wot-primary-6, #087f70)' } },
})
