# Lora Super Admin — Compact Template

Ky është standardi bazë për të gjitha faqet nën `/super-admin`.

## Theme

- Canvas: `#f5f7f6`
- Surface/sidebar: `#ffffff`
- Text: `#16211d`
- Border: `#dfe6e2`
- Brand: `#1d765f`; dark `#104c3d`; soft `#eff9f5`
- Card radius: `14px`
- Controls/buttons: `38px` lartësi, `10px` radius
- Table rows: `58px`
- Spacing scale: `8 / 12 / 16 / 24px`

## Reusable classes

Stilet janë të izoluara nën `.super-admin-shell` në `resources/css/app.css`:

- `.sa-page` — gjerësia standarde e përmbajtjes
- `.sa-card` — surface, border, radius dhe shadow
- `.sa-card-header` — header kompakt i kartës
- `.sa-control` — input/select standard
- `.sa-button` — buton sekondar
- `.sa-button.sa-button-primary` — veprim primar

## Layout rules

- Sidebar i bardhë: `228px`; i mbyllur: `76px`.
- Active state përdor vetëm nuancat jeshile të Lora PMS.
- Logoja hap `lorapms.com` në tab të ri.
- Topbar mban vetëm njoftimet dhe avatarin; Profili/Dil janë në dropdown.
- Informacioni operacional vendoset në karta kompakte dhe tabela, jo në modalë të mëdhenj.

## Source of truth

- Layout: `resources/js/Layouts/SuperAdminLayout.vue`
- Tokens/classes: `resources/css/app.css`
- Reference implementation: `resources/js/Pages/SuperAdmin/Dashboard.vue`
- Static approved mockup: `public/mockups/super-admin-compact-template.html`
