# PMS 360° — Auditi fillestar i raporteve

## Përmbledhje

- 23 raporte aktive dhe 1 katalog raportesh.
- Të gjitha faqet përdorin `ReportShell`; shumica përdorin `ReportKpiGrid`.
- Të dhënat e nevojshme për budget, pickup real, fiskalizim, maintenance dhe inventory ekzistojnë pjesërisht.
- Problemi kryesor nuk është mungesa e faqeve, por konsistenca e formulave dhe data e njohjes së revenue.

## Gjetje kritike

### P0 — Duhet rregulluar para ridizajnimit

1. **Revenue dhe netët njihen sipas check-in-it të rezervimit.**
   - Executive, Performance, Channels dhe VAT marrin të gjithë vlerën e qëndrimit kur check-in bie në periudhë.
   - Qëndrimet që kalojnë kufirin e periudhës shtrembërojnë Revenue, Occupancy, ADR dhe RevPAR.
   - Standardi i ri: revenue dhe netët ndahen sipas `stay_date`.

2. **Available room nights nuk zbresin downtime.**
   - Dhomat në maintenance llogariten si inventar i disponueshëm.
   - Standardi i ri: `sellable room nights = inventory − out-of-order nights`.

3. **TVSH nuk buron nga faturat fiskale.**
   - Raporti aktual e llogarit nga rezervimet dhe POS orders.
   - Standardi i ri: vetëm `FiscalDocument` dhe `PosFiscalDocument` me status `fiscalized`.

4. **Room charge në POS llogaritet si arkëtim.**
   - Është transferim në folio, jo hyrje cash/card.
   - Duhet ndarë nga collected cash dhe reconciliation.

5. **Pace aktual nuk është pickup real.**
   - Paraqet on-books të sotëm, jo ndryshimin mes dy snapshot-eve.
   - `room_inventory_snapshots` ekziston dhe duhet përdorur për pickup 1/3/7/14/30 ditë.

### P1 — Duhet plotësuar gjatë moduleve

- Outstanding nuk ka due date dhe aging buckets.
- Refunds nuk trajtohen si flow më vete.
- Cancellations maten sipas stay date; mungon cancellation-date view.
- Guest lifetime value përdor booked revenue, jo realized net revenue.
- Forecast ekziston në dashboard, por nuk ka accuracy history.
- Budget ekziston në databazë, por nuk lidhet me raportet.
- Maintenance ka të dhëna SLA; mungon raporti analitik.
- Inventory dhe supplier data ekzistojnë; mungojnë raportet.

## Vendimi për çdo raport

| Raporti aktual | Vendimi | Moduli i ri | Ndryshimi kryesor |
|---|---|---|---|
| Executive | Ridizajno | Executive | Stay-date metrics, TRevPAR, budget, forecast, alerts |
| Performance | Ridizajno | Revenue | Daily + room type performance |
| Pace | Rindërto | Revenue | Pickup nga snapshots |
| Channels | Ridizajno | Distribution | Commission, net revenue, Direct vs OTA |
| Booking Behavior | Mbaj | Distribution/Guest | Lead time + LOS + segment |
| Payments | Rindërto | Finance | Collection vs transfer + reconciliation |
| Outstanding | Rindërto | Finance | Aging buckets dhe drill-down |
| VAT | Rindërto | Finance | Vetëm dokumente fiskale |
| Discounts | Zgjero | Finance | Discounts + refunds |
| Shifts | Mbaj | Finance/POS | Reconciliation dhe over/short |
| Arrivals | Bashko | Operations Hub | Tab Arrivals |
| Departures | Bashko | Operations Hub | Tab Departures |
| In-house | Bashko | Operations Hub | Tab In-house |
| Room Status | Zgjero | Operations | Room readiness + downtime |
| Housekeeping | Zgjero | Operations | Productivity + turnaround |
| Guests | Bashko | Guest Intelligence | Directory + segments |
| Repeat Guests | Bashko | Guest Intelligence | LTV + retention |
| Nationality | Mbaj | Guest Intelligence | Market mix |
| Cancellations | Zgjero | Guest Intelligence | Cancellation/no-show risk |
| POS Sales | Bashko | POS Performance | Sales + category/item margin |
| POS Hourly | Bashko | POS Performance | Tab hourly |
| POS Payment Mix | Bashko | POS Performance | Tab payments |
| POS Voids | Bashko | POS Performance | Tab controls |

## KPI contract — versioni 1

| KPI | Formula standarde |
|---|---|
| Room Revenue | Shuma e revenue të akomodimit e alokuar për çdo stay date |
| Occupancy | Occupied room nights / Sellable room nights |
| ADR | Room Revenue / Occupied room nights |
| RevPAR | Room Revenue / Sellable room nights |
| TRevPAR | Total operational revenue / Sellable room nights |
| Net Revenue | Gross revenue − commissions − discounts − refunds |
| ALOS | Occupied room nights / Stays |
| Lead Time | Check-in date − Booking created date |
| Pickup | On-books(tani) − On-books(snapshot reference) për të njëjtën stay date |
| Cancellation Rate | Cancelled bookings / Total created bookings për cohort-in e zgjedhur |
| Forecast Accuracy | 1 − abs(Forecast − Actual) / Actual |

## Prerequisites teknike

1. `ReportingPeriod` dhe `KpiDefinition` si kontrata të përbashkëta.
2. Stay-date revenue allocation service.
3. Sellable inventory calendar me downtime.
4. Comparison service: previous period dhe previous year.
5. Budget/forecast adapter.
6. Fiscal revenue adapter.
7. Standard report query DTO dhe caching.
8. Dataset reference për testet e formulave.

## Hapi pasues

Ndërtohet Foundation: kontratat KPI, stay-date allocation, sellable inventory dhe comparison service. Vetëm pas testeve të tyre fillon Executive Dashboard.
