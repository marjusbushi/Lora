// Labels live in the locale files under settingsTabs.navigation.*;
// consumers resolve labelKey with t()/translate() so the sidebar follows the active locale.
export const settingsTabs = [
    { id: 'hotel', labelKey: 'settingsTabs.navigation.tabs.hotel', group: 'hotel' },
    { id: 'room-types', labelKey: 'settingsTabs.navigation.tabs.roomTypes', group: 'hotel' },
    { id: 'floors', labelKey: 'settingsTabs.navigation.tabs.floors', group: 'hotel' },
    { id: 'amenities', labelKey: 'settingsTabs.navigation.tabs.amenities', group: 'hotel' },
    // Web Studio (task #411) zëvendëson tab-et e vjetra 'website' + 'about' —
    // gjithë web-i publik editohet në një faqe të vetme me parapamje live.
    { id: 'web-studio', sidebarId: 'web-studio', labelKey: 'settingsTabs.navigation.tabs.webStudio', group: 'hotel', href: '/pms/web-studio' },
    { id: 'booking-policies', labelKey: 'settingsTabs.navigation.tabs.bookingPolicies', group: 'operations' },
    { id: 'pricing-programs', labelKey: 'settingsTabs.navigation.tabs.pricingPrograms', group: 'operations' },
    { id: 'market-rates', labelKey: 'settingsTabs.navigation.tabs.marketRates', group: 'operations' },
    { id: 'pos', labelKey: 'settingsTabs.navigation.tabs.pos', group: 'operations', module: 'pos' },
    { id: 'menu', labelKey: 'settingsTabs.navigation.tabs.menu', group: 'operations', module: 'pos' },
    { id: 'housekeeping', labelKey: 'settingsTabs.navigation.tabs.housekeeping', group: 'operations', module: 'housekeeping' },
    { id: 'beach', labelKey: 'settingsTabs.navigation.tabs.beach', group: 'operations', module: 'beach' },
    { id: 'financial', labelKey: 'settingsTabs.navigation.tabs.financial', group: 'operations' },
    { id: 'currencies', labelKey: 'settingsTabs.navigation.tabs.currencies', group: 'operations', module: 'finance' },
    { id: 'integrations', labelKey: 'settingsTabs.navigation.tabs.integrations', group: 'automation' },
    { id: 'ai', sidebarId: 'lora-ai', labelKey: 'settingsTabs.navigation.tabs.ai', group: 'automation', href: '/pms/lora-ai' },
    { id: 'channel-manager', labelKey: 'settingsTabs.navigation.tabs.channelManager', group: 'automation', module: 'channel_manager' },
    { id: 'faqs', labelKey: 'settingsTabs.navigation.tabs.faqs', group: 'automation', module: 'messages' },
    { id: 'whatsapp', labelKey: 'settingsTabs.navigation.tabs.whatsapp', group: 'automation', module: 'messages' },
    { id: 'users', labelKey: 'settingsTabs.navigation.tabs.users', group: 'system' },
    { id: 'notifications', labelKey: 'settingsTabs.navigation.tabs.notifications', group: 'system' },
    { id: 'security', labelKey: 'settingsTabs.navigation.tabs.security', group: 'system' },
    { id: 'history', labelKey: 'settingsTabs.navigation.tabs.history', group: 'system' },
];

export const settingsGroups = [
    { id: 'hotel', labelKey: 'settingsTabs.navigation.groups.hotel', icon: 'Hotel' },
    { id: 'operations', labelKey: 'settingsTabs.navigation.groups.operations', icon: 'BriefcaseBusiness' },
    { id: 'automation', labelKey: 'settingsTabs.navigation.groups.automation', icon: 'Bot' },
    { id: 'system', labelKey: 'settingsTabs.navigation.groups.system', icon: 'ShieldCheck' },
];

export function visibleSettingsTabs(modules = {}) {
    return settingsTabs.filter((tab) => !tab.module || modules[tab.module] === true);
}
