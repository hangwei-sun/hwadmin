export interface HwTableColumn<T extends Record<string, any> = Record<string, any>> {
  prop: (keyof T & string) | string
  label: string
  width?: number | string
  minWidth?: number | string
  align?: 'left' | 'center' | 'right'
  fixed?: 'left' | 'right' | boolean
  sortable?: boolean | 'custom'
  showOverflowTooltip?: boolean
  slot?: string
  formatter?: (row: T, value: any, index: number) => string
}
