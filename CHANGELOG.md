# Changelog

All notable MarifeX Advanced Analytics changes are recorded here from version `0.14.1-dev` onward.

The format follows Keep a Changelog. Development versions remain unreleased until live validation, commit, tag and push are complete.

## [Unreleased]

## [0.14.1-dev] - 2026-08-12

### Fixed

- Moved the widget-settings drawer to the document body so GridStack widget stacking order cannot paint charts, tables, resize handles or neighbouring cards over it.
- Preserved widget palette variables in the body-level drawer and focused the first settings control when it opens.
- Added structural regression checks requiring document-level drawer rendering and prohibiting the ineffective nested-card z-index workaround.

### Validation

- Verify settings from early, middle and final dashboard widgets across KPI, chart, table, matrix and donut presentations.
- Verify the complete drawer remains above all widgets while scrolling and that Cancel, Apply & close and Escape work.

