// Shared props of the Addis One Intelligence pages (IntelligenceController::frame()).

export type IntelligenceSection = 'business' | 'knowledge' | 'system' | 'diagnostics' | 'governance';

export type IntelligenceAreaInfo = {
    key: string;
    section: IntelligenceSection;
    domain: 'business' | 'technical' | 'governance';
    icon: string;
    href: string;
};

export type IntelligenceFrame = {
    areas: IntelligenceAreaInfo[];
    sections: IntelligenceSection[];
    ai: { enabled: boolean; provider: string };
    can: { governance: boolean; settings: boolean };
};
