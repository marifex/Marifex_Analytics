export type WidgetPaletteKey = 'cream_gold' | 'ocean' | 'mint' | 'lavender' | 'charcoal_gold' | 'neutral' | 'classic_blue' | 'teal_green' | 'deep_purple' | 'warm_amber' | 'coral_red' | 'sky_blue' | 'bright_orange' | 'rose_pink' | 'forest_green' | 'slate_gray';

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
  { key: 'charcoal_gold', label: 'Charcoal Gold', type: 'Solid', background: '#263247', backgroundEnd: '#263247', text: '#ffffff', muted: '#d7deea', border: '#58657a', colors: ['#f2bd31', '#263247', '#ffe08a', '#58657a', '#d99a00', '#8b95a7', '#fff0b8', '#111827', '#bd7f00', '#cbd5e1', '#e6aa15', '#3f4b5f'] },
  { key: 'neutral', label: 'Classic White', type: 'Solid', background: '#ffffff', backgroundEnd: '#ffffff', text: '#263247', muted: '#6b7688', border: '#dfe4ec', colors: ['#4361ee', '#a7cf24', '#50597b', '#f05d7b', '#7656b5', '#34a853', '#f5bd00', '#009bb8', '#f47b3d', '#586174', '#9a60b4', '#ea7ccc'] },
  { key: 'classic_blue', label: 'Classic Blue', type: 'Gradient', background: '#EFF6FF', backgroundEnd: '#DBEAFE', text: '#1E3A8A', muted: '#475569', border: '#BFDBFE', colors: ['#1D4ED8', '#2563EB', '#3B82F6', '#60A5FA', '#0EA5E9', '#64748B'] },
  { key: 'teal_green', label: 'Teal Green', type: 'Gradient', background: '#ECFDF5', backgroundEnd: '#D1FAE5', text: '#064E3B', muted: '#475569', border: '#A7F3D0', colors: ['#047857', '#10B981', '#34D399', '#0F766E', '#14B8A6', '#64748B'] },
  { key: 'deep_purple', label: 'Deep Purple', type: 'Gradient', background: '#F5F3FF', backgroundEnd: '#EDE9FE', text: '#4C1D95', muted: '#475569', border: '#DDD6FE', colors: ['#5B21B6', '#8B5CF6', '#A78BFA', '#7E22CE', '#C084FC', '#64748B'] },
  { key: 'warm_amber', label: 'Warm Amber', type: 'Gradient', background: '#FFFBEB', backgroundEnd: '#FEF3C7', text: '#78350F', muted: '#64748B', border: '#FDE68A', colors: ['#B45309', '#F59E0B', '#FBBF24', '#D97706', '#FCD34D', '#78716C'] },
  { key: 'coral_red', label: 'Coral Red', type: 'Gradient', background: '#FEF2F2', backgroundEnd: '#FEE2E2', text: '#7F1D1D', muted: '#64748B', border: '#FECACA', colors: ['#B91C1C', '#EF4444', '#F87171', '#BE123C', '#FB7185', '#64748B'] },
  { key: 'sky_blue', label: 'Sky Blue', type: 'Gradient', background: '#F0F9FF', backgroundEnd: '#E0F2FE', text: '#0C4A6E', muted: '#475569', border: '#BAE6FD', colors: ['#2563EB', '#60A5FA', '#93C5FD', '#0284C7', '#38BDF8', '#64748B'] },
  { key: 'bright_orange', label: 'Bright Orange', type: 'Gradient', background: '#FFF7ED', backgroundEnd: '#FFEDD5', text: '#7C2D12', muted: '#64748B', border: '#FED7AA', colors: ['#C2410C', '#F97316', '#FB923C', '#EA580C', '#FDBA74', '#64748B'] },
  { key: 'rose_pink', label: 'Rose Pink', type: 'Gradient', background: '#FFF5F5', backgroundEnd: '#FFE4E6', text: '#881337', muted: '#475569', border: '#FECDD3', colors: ['#BE185D', '#F472B6', '#FB7185', '#DB2777', '#FDA4AF', '#64748B'] },
  { key: 'forest_green', label: 'Forest Green', type: 'Gradient', background: '#F0FDF4', backgroundEnd: '#DCFCE7', text: '#14532D', muted: '#475569', border: '#BBF7D0', colors: ['#065F46', '#34D399', '#6EE7B7', '#15803D', '#4ADE80', '#64748B'] },
  { key: 'slate_gray', label: 'Slate Gray', type: 'Gradient', background: '#F8FAFC', backgroundEnd: '#F1F5F9', text: '#0F172A', muted: '#475569', border: '#E2E8F0', colors: ['#4B5563', '#9CA3AF', '#CBD5E1', '#334155', '#64748B', '#94A3B8'] },
];

export function widgetPalette(key?: string): WidgetPalette {
  return widgetPalettes.find(item => item.key === key) ?? widgetPalettes[0];
}
