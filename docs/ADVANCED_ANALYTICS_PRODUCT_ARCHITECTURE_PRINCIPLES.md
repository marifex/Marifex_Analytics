# MarifeX Advanced Analytics - Product Architecture Principles

Status: **Permanent governing product-architecture reference**

Integrated into the authoritative scope: **2026-08-13**

This document governs Phase 5A, Phase 5B, Phase 5C, reserved Phase 5D and future MarifeX Advanced Analytics proposals. Detailed metric formulas, presentation rules and phase acceptance criteria remain controlled by [DASHBOARD_DESIGN_SCOPE.md](DASHBOARD_DESIGN_SCOPE.md). This reference does not reopen or redefine settled Phase 5A or Phase 5C analytical requirements.

## 1. Product release position

Phase 5A plus Phase 5C constitute the complete initial MarifeX Advanced Analytics product. The initial product is commercially and operationally complete without Phase 5B or Phase 5D.

The initial shipped capability comprises deterministic KPIs and movement analysis, period comparison, materiality-controlled insight selection, contribution callouts, calculation transparency, controlled evidence navigation, governed visual palettes, accessible chart inspection, export parity, auditability and entity-scoped analytical access.

Phase 5B and Phase 5D are subsequent analytical capability updates. Neither is a blocker to shipping the Phase 5A plus Phase 5C product. Phase 5D remains reserved and requires separate written implementation approval.

The Advanced Analytics Phase 5A-5D naming is distinct from the repository roadmap document `PHASE_5.md`, which governs scheduled reporting and exports.

## 2. Permanent capability hierarchy

Every Advanced Analytics capability must be classified in this order:

1. **Observe - What is happening?** Current-state KPIs, composition, absolute values and operational distributions.
2. **Compare - What changed?** Period movement, absolute and percentage change, contribution analysis and material findings.
3. **Evaluate - Is it acceptable?** Governed targets, warning bands, variance, breach, persistence, recovery and exception ranking.
4. **Explain - Why did it happen?** Certified dimensional decomposition, contributor analysis and deterministic diagnostic evidence. Explanation must remain factual and non-causal unless a future scope explicitly certifies causal analysis.
5. **Act - What should I do?** Future governed recommendations, operational decision support and advisory intelligence.

The governing progression is **Observe -> Compare -> Evaluate -> Explain -> Act**. A capability is not promoted into an earlier layer to satisfy an isolated feature request.

## 3. Certified calculation protection

A higher analytical layer may consume certified evidence produced by a lower layer, but it must never silently redefine, replace or independently recalculate the certified lower-layer calculation.

This protection applies to Phase 5B decomposition, Phase 5D target evaluation, Phase 6 comparison, and any future recommendation or AI layer. Future AI or advisory systems consume certified analytical facts; they do not replace them with alternative aggregations. If a certified calculation is wrong, the owning certified layer and its formula version must be corrected through controlled scope change.

## 4. Progressive Analytical Activation

Progressive Analytical Activation is mandatory Phase 5A production behavior. Analytics must be useful immediately after installation, while deeper claims activate only when their certified evidence requirements are satisfied. Activation depends on data readiness, not elapsed calendar time alone.

The governed activation states are:

1. **Current State** - current certified values and compositions are available where source data permits. It may use an authorized certified live value with query-time evidence or an authorized certified completed snapshot with snapshot-freshness evidence, as governed by the metric registry. No historical movement is implied.
2. **Observed Movement** - sufficient forward certified observations support a deterministic change from a stable, system-owned monitoring baseline. The explicit comparison basis is **Since monitoring began**. It is not presented as a certified period-over-period comparison.
3. **Comparable Window** - one complete selected analytical horizon supports certified within-window statistics and composition where the metric permits. It does not imply a prior equal-window comparison.
4. **Certified Period Comparison** - the complete current and immediately previous equal horizons, plus any governed boundary evidence, support the settled Phase 5A comparison. The basis is explicit, for example **vs prior 7 days**.

The states are visually distinguishable without intrusive warning banners. Availability and affordances follow the complete **Deepest available evidence** rule in Section 7.

The monitoring baseline is identified by governed data-collection evidence, not by the oldest row retained at query time or the date on which a user first selects a scope or filter. Retention must preserve the baseline identity and its certified evidence or suppress Observed Movement; it must never silently advance the baseline. Each governed entity scope, recursive setting, supported group filter, metric and grain resolves only its own baseline and never reuses another scope's baseline.

`OBSERVED_MOVEMENT` is a factual absolute change only. It is not eligible for materiality evaluation, sustained-direction analysis, the Executive insight brief or any claim requiring certified period-over-period comparison.

## 5. Analytical provenance

Every decision-relevant analytical output carries one of these provenance classes and exposes it through calculation inspection:

- **OBSERVED** - directly obtained from certified operational or snapshot data. This is the strongest source provenance.
- **CERTIFIED_BOOTSTRAP** - reconstructed from GLPI history through an approved deterministic method and independently reconciled for that metric.
- **DERIVED** - calculated from one or more certified analytical inputs. A derived result inherits the weakest provenance of all inputs, recursively.
- **UNCERTIFIED_RECONSTRUCTION** - reconstructed history that does not meet certification and reconciliation requirements.

For inheritance, `OBSERVED` is stronger than `CERTIFIED_BOOTSTRAP`. A DERIVED result retains its structural provenance as `DERIVED` and records its effective inherited source provenance. Both remain available through calculation inspection. Any input with `UNCERTIFIED_RECONSTRUCTION` makes the result ineligible for certified use rather than producing a weaker certified result.

`UNCERTIFIED_RECONSTRUCTION` must never feed certified KPIs, comparisons, materiality decisions, insights, target evaluation, exception ranking or certified recommendations. This restriction is enforced by the analytical calculation layer, not only by presentation code.

## 6. Analytical confidence inspection

Every material or decision-relevant certified value exposes the evidence required to understand why it can be trusted. Where applicable, inspection includes:

- formula and formula version;
- current analytical period and comparison period;
- materiality rule and pass, bypass or suppression outcome;
- data coverage and readiness state;
- provenance and inherited provenance;
- entity and supported filter scope;
- source freshness and last refresh timestamp; and
- governed navigation to supporting evidence.

Inspection reflects the rule that actually surfaced or suppressed the finding. It must not simplify away an applicable denominator gate, zero-transition rule, critical bypass or authorization restriction.

## 7. Deepest available evidence

Every material finding leads to the deepest evidence available within currently shipped layers. A movement may lead to its certified contributor and then to authorized GLPI records. If a deeper layer has not shipped, the evidence path ends naturally at the deepest available certified evidence.

The product must not show disabled future analytical actions, `Coming soon` analytical buttons, unavailable drill paths or affordances implying that unimplemented analysis exists. A later shipped layer may extend the existing evidence path without redefining the lower-layer finding.

## 8. Read-only analytical boundary

Advanced Analytics remains read-only. Permitted evidence actions include inspecting calculations, viewing contributing dimensions, opening filtered authorized GLPI evidence, navigating to an authorized GLPI record and opening an authorized operational queue.

Analytics does not reassign tickets, change priority or status, perform approvals, execute workflows, remediate automatically, bulk-edit records or otherwise write back to GLPI. Any GLPI operational write-back requires separate future scope, threat modelling, permissions design and security review.

## 9. Historical baseline bootstrap boundary

Historical Baseline Bootstrap is not an approved production feature. It may be evaluated only through the controlled spike specified in the authoritative scope. A candidate metric can receive `CERTIFIED_BOOTSTRAP` provenance only after deterministic reconstruction and an independent metric-specific reconciliation method both pass the spike gate.

Until a later written amendment approves production bootstrap for named metrics, Progressive Analytical Activation remains the production cold-start mechanism.

If no candidate passes the current spike, Historical Baseline Bootstrap remains unapproved and inactive. No further bootstrap work may proceed without new written investigation approval based on materially changed evidence or assumptions. A failed spike does not permanently remove bootstrap as a future architectural possibility.

## 10. Reserved Phase 5D architecture

Phase 5D remains separate and unapproved for implementation. Its future deterministic lifecycle model is reserved for **Healthy**, **Warning**, **Breach**, **Persistent Breach** and **Recovering**, followed by Healthy only after the future governed recovery condition is satisfied.

The next lifecycle state may depend only on the current lifecycle state, the current certified period measurement, the applicable governed target or warning configuration, and bounded state metadata such as consecutive-period count. Arbitrary historical-pattern analysis cannot influence the transition function. Explicit streak metadata must not become implicit anomaly detection.

Target-band calculation and lifecycle state are separate concepts. A later Phase 5D amendment must reconcile them explicitly without redefining Phase 5A movement or any certified lower-layer value.

## 11. Governance

Every future Advanced Analytics proposal is reviewed against these principles before implementation approval. Scope must identify its hierarchy layer, certified inputs, provenance rules, readiness gate, evidence depth and read-only boundary. Silence or technical convenience is not approval to cross a layer or weaken a certified calculation.
