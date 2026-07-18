export const settingsTabs = [
    { id: 'hotel', labelSq: 'Të dhënat e hotelit', labelEn: 'Hotel information', group: 'hotel' },
    { id: 'room-types', labelSq: 'Tipet e dhomave', labelEn: 'Room types', group: 'hotel' },
    { id: 'floors', labelSq: 'Katet', labelEn: 'Floors', group: 'hotel' },
    { id: 'amenities', labelSq: 'Pajisjet', labelEn: 'Amenities', group: 'hotel' },
    { id: 'website', labelSq: 'Faqja Web', labelEn: 'Website', group: 'hotel' },
    { id: 'about', labelSq: 'Rreth Nesh', labelEn: 'About page', group: 'hotel' },
    { id: 'booking-policies', labelSq: 'Rezervimet & politikat', labelEn: 'Reservations & policies', group: 'operations' },
    { id: 'pricing-programs', labelSq: 'Çmimet & OTA', labelEn: 'Pricing & OTA', group: 'operations' },
    { id: 'market-rates', labelSq: 'Çmimet e tregut', labelEn: 'Market rates', group: 'operations' },
    { id: 'menu', labelSq: 'Menuja POS', labelEn: 'POS menu', group: 'operations', module: 'pos' },
    { id: 'housekeeping', labelSq: 'Housekeeping', labelEn: 'Housekeeping', group: 'operations', module: 'housekeeping' },
    { id: 'financial', labelSq: 'Financa', labelEn: 'Finance', group: 'operations' },
    { id: 'currencies', labelSq: 'Monedhat', labelEn: 'Currencies', group: 'operations', module: 'finance' },
    { id: 'integrations', labelSq: 'Integrimet', labelEn: 'Integrations', group: 'automation' },
    { id: 'ai', sidebarId: 'lora-ai', labelSq: 'Konfigurimi i Lora AI', labelEn: 'Lora AI configuration', group: 'automation', href: '/pms/lora-ai' },
    { id: 'channel-manager', labelSq: 'Channel Manager', labelEn: 'Channel Manager', group: 'automation', module: 'channel_manager' },
    { id: 'users', labelSq: 'Përdoruesit & rolet', labelEn: 'Users & roles', group: 'system' },
    { id: 'notifications', labelSq: 'Njoftimet', labelEn: 'Notifications', group: 'system' },
    { id: 'security', labelSq: 'Siguria', labelEn: 'Security', group: 'system' },
    { id: 'history', labelSq: 'Auditimi', labelEn: 'Audit', group: 'system' },
];

export const settingsGroups = [
    { id: 'hotel', labelSq: 'Hoteli', labelEn: 'Hotel', icon: 'Hotel' },
    { id: 'operations', labelSq: 'Operacionet', labelEn: 'Operations', icon: 'BriefcaseBusiness' },
    { id: 'automation', labelSq: 'Automatizimi', labelEn: 'Automation', icon: 'Bot' },
    { id: 'system', labelSq: 'Sistemi', labelEn: 'System', icon: 'ShieldCheck' },
];

export function visibleSettingsTabs(modules = {}) {
    return settingsTabs.filter((tab) => !tab.module || modules[tab.module] === true);
}
