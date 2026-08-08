export type WidgetPaletteKey = 'cream_gold' | 'ocean' | 'mint' | 'lavender' | 'charcoal_gold' | 'neutral';

export type WidgetPalette = {
  key: WidgetPaletteKey;
  label: string;
  type: 'Solid' | 'Monochrome' | 'Gradient';
  background: string;
  backgroundEnd: string;
  text: string;
  muted: string;
  border: string;
  colors: string[];
};

export const defaultWidgetPalette: WidgetPaletteKey = 'cream_gold';

export const widgetPalettes: WidgetPalette[] = [
  { key: 'cream_gold', label: 'Cream Gold', type: 'Gradient', background: '#fffdf4', backgroundEnd: '#fff0b8', text: '#3b3321', muted: '#776943', border: '#ecd27c', colors: ['#d99a00', '#f2bd31', '#ffda68', '#b87900', '#8d6411', '#f7c95f', '#c88c14', '#ffe59a', '#aa7410', '#6f5220', '#f3af17', '#d4b261'] },
  { key: 'ocean', label: 'Ocean Blue', type: 'Gradient', background: '#f5fbff', backgroundEnd: '#dcefff', text: '#17334f', muted: '#55728d', border: '#9bc9eb', colors: ['#1479c9', '#26a6d1', '#49c5b6', '#075d9a', '#5a8dee', '#1b91a8', '#70b7e6', '#087f8c', '#83d7cb', '#2c64ad', '#4a9fd8', '#0c718f'] },
  { key: 'mint', label: 'Mint', type: 'Monochrome', background: '#f5fcf8', backgroundEnd: '#def5e7', text: '#193d2c', muted: '#557463', border: '#9dd6b7', colors: ['#176b43', '#248653', '#2f9e66', '#42b578', '#62c58f', '#83d5a7', '#a4e3bf', '#0f5936', '#357a55', '#55a978', '#78bf95', '#9bd4b2'] },
  { key: 'lavender', label: 'Lavender', type: 'Gradient', background: '#fbf8ff', backgroundEnd: '#eadfff', text: '#35284f', muted: '#706384', border: '#c7afe9', colors: ['#7656b5', '#936bd0', '#b07ee2', '#5e46a1', '#c18ce8', '#8157bf', '#a472d4', '#d0a2ef', '#684ca7', '#9b7ac5', '#b891db', '#573c90'] },
  { key: 'charcoal_gold', label: 'Charcoal Gold', type: 'Solid', background: '#263247', backgroundEnd: '#263247', text: '#ffffff', muted: '#d7deea', border: '#58657a', colors: ['#f2bd31', '#ffe08a', '#d99a00', '#fff0b8', '#bd7f00', '#f7cf62', '#e6aa15', '#fff5d2', '#c78d16', '#f4c34a', '#aa7613', '#e9d49b'] },
  { key: 'neutral', label: 'Classic White', type: 'Solid', background: '#ffffff', backgroundEnd: '#ffffff', text: '#263247', muted: '#6b7688', border: '#dfe4ec', colors: ['#4361ee', '#a7cf24', '#50597b', '#f05d7b', '#7656b5', '#34a853', '#f5bd00', '#009bb8', '#f47b3d', '#586174', '#9a60b4', '#ea7ccc'] },
];

export function widgetPalette(key?: string): WidgetPalette {
  return widgetPalettes.find(item => item.key === key) ?? widgetPalettes[0];
}
