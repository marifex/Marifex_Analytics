export type Point = { date: string; value: number };
export type DimensionPoint = Point & { dimension_id: number; dimension: string };
export type MetricResponse = {
  metric: string;
  label: string;
  source: 'live' | 'data_mart';
  value?: number;
  series?: Array<Point | DimensionPoint>;
};
export type WidgetType = 'kpi' | 'line' | 'bar' | 'donut' | 'table';
export type WidgetDefinition = { id: string; metric: string; type: WidgetType; title: string; w: number; h: number };
export type DashboardDefinition = { version: number; dateRangeDays: number; widgets: WidgetDefinition[] };
export type SavedDashboard = { id: number | null; name: string; definition: DashboardDefinition; date_mod: string | null };
