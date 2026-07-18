# PMS 360° — Plani i dashboard-eve dhe raporteve

## Standardi i përbashkët

- Një `ReportShell` për header, filtra, krahasim, eksport dhe printim.
- Një shkallë tipografike dhe spacing identik me mockup-in e miratuar.
- KPI cards, charts, alerts dhe tabela si komponentë të ripërdorshëm.
- Tekste të shkurtra; shpjegime vetëm për alerts dhe rekomandime.
- Filtra standard: periudha, krahasimi, departamenti, kanali dhe tipologjia.
- Çdo KPI ka një formulë të vetme të dokumentuar dhe të testuar.
- Currency, timezone, permissions dhe module entitlements respektohen në çdo raport.
- Export PDF/Excel, print, empty/loading/error state dhe responsive në çdo faqe.

## Inventari aktual

### Mbaj dhe ridizajno

- Executive, Performance, Pace, Channels.
- Payments, Outstanding, VAT, Discounts, Shifts.
- Arrivals, Departures, In-house, Room Status, Housekeeping.
- Guests, Repeat Guests, Nationality, Booking Behavior, Cancellations.
- POS Sales, POS Hourly, Payment Mix, Voids.

### Bashko

- Arrivals + Departures + In-house → `Operations Hub` me tabs.
- Guests + Repeat Guests → `Guest Value` me segmentim dhe LTV.
- POS Sales + Hourly + Payment Mix + Voids → `POS Performance` me tabs.

### Shto

- Budget/Target dhe Forecast Accuracy.
- TRevPAR dhe revenue sipas departamentit.
- Direct vs OTA dhe net revenue pas komisioneve.
- Aging buckets, reconciliation, refunds dhe cash flow.
- Maintenance SLA, downtime dhe probleme të përsëritura.
- Guest segments dhe cancellation/no-show risk.
- Food cost, gross margin, stock valuation, consumption dhe low-stock.
- Supplier performance.

## Faza 0 — Foundation

**Statusi: përfunduar dhe në staging.**

1. Katalogu i KPI-ve: formula, burimi, timezone, currency dhe exclusions.
2. Komponentët standard: `ReportShell`, `ReportFilterBar`, `KpiGrid`, charts, alerts dhe data table.
3. Query/service layer i përbashkët për period comparison, budget dhe forecast.
4. Teste për formulat, tenant isolation, permissions dhe exports.

**Dalja:** standardi teknik dhe vizual që përdoret nga çdo modul.

## Faza 1 — Executive Dashboard

**Statusi: implementuar; në pritje të review në staging.**

- Revenue, Occupancy, ADR, RevPAR, TRevPAR.
- Krahasim me periudhën e kaluar dhe vitin e kaluar.
- Target/budget, forecast dhe forecast accuracy.
- Alerts: kërkesë e lartë, pace i dobët, outstanding, rooms at risk.
- Drill-down nga çdo KPI te raporti burim.

**Dalja:** dashboard-i kryesor për vendimmarrje ditore.

## Faza 2 — Revenue & Distribution

- Performance ditore dhe sipas tipologjisë.
- Pickup/pace real me booking-date snapshots.
- Kanale, komisione dhe net revenue.
- Direct vs OTA.
- Lead time dhe length of stay.

**Dalja:** 5 raporte të lidhura me njëri-tjetrin dhe me dashboard-in.

## Faza 3 — Finance

- Payments dhe reconciliation.
- Outstanding me buckets: 0–7, 8–30, 31–60, 60+ ditë.
- TVSH vetëm nga faturat fiskale.
- Discounts, refunds dhe cash flow.
- Revenue sipas departamentit.

**Dalja:** kontroll financiar dhe gjurmueshmëri deri te pagesa/fatura/rezervimi.

## Faza 4 — Operations

- Arrivals, Departures dhe In-house si tabs.
- Room readiness.
- Housekeeping productivity dhe turnaround.
- Maintenance SLA, downtime dhe recurring issues.

**Dalja:** një Operations Hub për ekipin e recepsionit dhe operacioneve.

## Faza 5 — Guest Intelligence

- Repeat guests dhe lifetime value.
- Nationality dhe booking behavior.
- Segmente: leisure, business, family, long-stay, high-value.
- Cancellation/no-show risk me faktorë të shpjegueshëm.

**Dalja:** profil segmentesh dhe mundësi konkrete retention/upsell.

## Faza 6 — POS & Inventory

- Sales, hourly performance, payment mix dhe voids.
- Top items dhe kategori.
- Food cost dhe gross margin.
- Stock valuation, consumption dhe low-stock.
- Supplier performance.

**Dalja:** pamje e plotë nga shitja deri te kostoja dhe furnitori.

## Faza 7 — Quality & rollout

- Kontroll i rezultateve me dataset reference.
- Performance budgets dhe caching për query-t e rënda.
- Audit log për eksportet dhe të dhënat financiare.
- SQ/EN, responsive, accessibility dhe print layout.
- Release gradual në staging sipas modulit; pastaj production.

## Rendi i implementimit

1. Foundation.
2. Executive Dashboard.
3. Revenue & Distribution.
4. Finance.
5. Operations.
6. Guest Intelligence.
7. POS & Inventory.
8. Audit final dhe rollout.

Çdo fazë mbyllet me: implementim → teste → review në staging → miratim → faza tjetër.
