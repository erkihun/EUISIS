import { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement>;

const base: IconProps = {
    xmlns: 'http://www.w3.org/2000/svg',
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 2,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
};

export function LayoutDashboard(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="14" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
        </svg>
    );
}

export function Building2(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M6 22V4a2 2 0 012-2h8a2 2 0 012 2v18zM2 22h20M14 11h.01M14 7h.01M10 11h.01M10 7h.01M10 15h.01M14 15h.01" />
        </svg>
    );
}

export function Users(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" />
        </svg>
    );
}

export function CreditCard(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <rect x="1" y="4" width="22" height="16" rx="2" ry="2" />
            <line x1="1" y1="10" x2="23" y2="10" />
        </svg>
    );
}

export function Store(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
            <polyline points="9 22 9 12 15 12 15 22" />
        </svg>
    );
}

export function Layers(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <polygon points="12 2 2 7 12 12 22 7 12 2" />
            <polyline points="2 17 12 22 22 17" />
            <polyline points="2 12 12 17 22 12" />
        </svg>
    );
}

export function ScrollText(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M8 21h12a2 2 0 002-2v-2H10v2a2 2 0 11-4 0V5a2 2 0 10-4 0v3h4" />
            <path d="M19 17V5a2 2 0 00-2-2H4" />
            <path d="M15 8h-5M15 12h-5" />
        </svg>
    );
}

export function X(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
        </svg>
    );
}

export function MenuIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <line x1="3" y1="12" x2="21" y2="12" />
            <line x1="3" y1="6" x2="21" y2="6" />
            <line x1="3" y1="18" x2="21" y2="18" />
        </svg>
    );
}

export function Sun(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <circle cx="12" cy="12" r="5" />
            <line x1="12" y1="1" x2="12" y2="3" />
            <line x1="12" y1="21" x2="12" y2="23" />
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" />
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78" />
            <line x1="1" y1="12" x2="3" y2="12" />
            <line x1="21" y1="12" x2="23" y2="12" />
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" />
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22" />
        </svg>
    );
}

export function Moon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
        </svg>
    );
}

export function ChevronDown(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <polyline points="6 9 12 15 18 9" />
        </svg>
    );
}

export function UserIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2" />
            <circle cx="12" cy="7" r="4" />
        </svg>
    );
}

export function LogOut(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9" />
        </svg>
    );
}

export function SettingsIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" />
        </svg>
    );
}

export function Inbox(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <polyline points="22 12 16 12 14 15 10 15 8 12 2 12" />
            <path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z" />
        </svg>
    );
}

export function SearchIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <circle cx="11" cy="11" r="8" />
            <line x1="21" y1="21" x2="16.65" y2="16.65" />
        </svg>
    );
}

export function AlertTriangle(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            <line x1="12" y1="9" x2="12" y2="13" />
            <line x1="12" y1="17" x2="12.01" y2="17" />
        </svg>
    );
}

export function CheckCircle(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M22 11.08V12a10 10 0 11-5.93-9.14" />
            <polyline points="22 4 12 14.01 9 11.01" />
        </svg>
    );
}

export function ChevronRight(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <polyline points="9 18 15 12 9 6" />
        </svg>
    );
}

export function Plus(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <line x1="12" y1="5" x2="12" y2="19" />
            <line x1="5" y1="12" x2="19" y2="12" />
        </svg>
    );
}

export function Upload(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
            <polyline points="17 8 12 3 7 8" />
            <line x1="12" y1="3" x2="12" y2="15" />
        </svg>
    );
}

export function PencilIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
        </svg>
    );
}

export function ArchiveIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <polyline points="21 8 21 21 3 21 3 8" />
            <rect x="1" y="3" width="22" height="5" />
            <line x1="10" y1="12" x2="14" y2="12" />
        </svg>
    );
}

export function MoreVertical(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <circle cx="12" cy="5" r="1" fill="currentColor" />
            <circle cx="12" cy="12" r="1" fill="currentColor" />
            <circle cx="12" cy="19" r="1" fill="currentColor" />
        </svg>
    );
}

export function ShieldCheck(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
            <polyline points="9 12 11 14 15 10" />
        </svg>
    );
}

export function Briefcase(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <rect x="2" y="7" width="20" height="14" rx="2" ry="2" />
            <path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16" />
        </svg>
    );
}

export function ChevronUp(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <polyline points="18 15 12 9 6 15" />
        </svg>
    );
}

export function FilterIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
        </svg>
    );
}

export function RefreshIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <polyline points="23 4 23 10 17 10" />
            <polyline points="1 20 1 14 7 14" />
            <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" />
        </svg>
    );
}

export function EyeIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
            <circle cx="12" cy="12" r="3" />
        </svg>
    );
}

export function TrashIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <polyline points="3 6 5 6 21 6" />
            <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2" />
        </svg>
    );
}

// ── Additional navigation icons ────────────────────────────────────────────

/** Tags — Organisation Types */
export function TagsIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z" />
            <line x1="7" y1="7" x2="7.01" y2="7" />
        </svg>
    );
}

/** GitBranch — Organisation Units */
export function GitBranchIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <line x1="6" y1="3" x2="6" y2="15" />
            <circle cx="18" cy="6" r="3" />
            <circle cx="6" cy="18" r="3" />
            <path d="M18 9a9 9 0 01-9 9" />
        </svg>
    );
}

/** Component (puzzle piece) — Organisation Unit Types */
export function ComponentIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M5.5 8.5 9 12l-3.5 3.5L2 12l3.5-3.5z" />
            <path d="m12 2 3.5 3.5L12 9 8.5 5.5 12 2z" />
            <path d="M18.5 8.5 22 12l-3.5 3.5L15 12l3.5-3.5z" />
            <path d="m12 15 3.5 3.5L12 22l-3.5-3.5L12 15z" />
        </svg>
    );
}

/** Hash — Code Rules */
export function HashIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <line x1="4" y1="9" x2="20" y2="9" />
            <line x1="4" y1="15" x2="20" y2="15" />
            <line x1="10" y1="3" x2="8" y2="21" />
            <line x1="16" y1="3" x2="14" y2="21" />
        </svg>
    );
}

/** GitFork — Hierarchy Versions */
export function GitForkIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <circle cx="12" cy="18" r="3" />
            <circle cx="6" cy="6" r="3" />
            <circle cx="18" cy="6" r="3" />
            <path d="M18 9v2c0 .6-.4 1-1 1H7c-.6 0-1-.4-1-1V9" />
            <line x1="12" y1="12" x2="12" y2="15" />
        </svg>
    );
}

/** Network — Hierarchy / Tree View */
export function NetworkIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <rect x="9" y="2" width="6" height="4" rx="1" />
            <rect x="2" y="17" width="6" height="4" rx="1" />
            <rect x="16" y="17" width="6" height="4" rx="1" />
            <path d="M12 6v4M5.5 17v-4h13v4" />
        </svg>
    );
}

/** UserPlus — Create Employee */
export function UserPlusIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <line x1="19" y1="8" x2="19" y2="14" />
            <line x1="16" y1="11" x2="22" y2="11" />
        </svg>
    );
}

/** ArrowLeftRight — Employee Transfers */
export function ArrowLeftRightIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M8 3L4 7l4 4" />
            <path d="M4 7h16" />
            <path d="M16 21l4-4-4-4" />
            <path d="M20 17H4" />
        </svg>
    );
}

/** XCircle — Error / failure indicator */
export function XCircle(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <circle cx="12" cy="12" r="10" />
            <line x1="15" y1="9" x2="9" y2="15" />
            <line x1="9" y1="9" x2="15" y2="15" />
        </svg>
    );
}

/** InfoIcon — Informational indicator */
export function InfoIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="16" x2="12" y2="12" />
            <line x1="12" y1="8" x2="12.01" y2="8" />
        </svg>
    );
}

/** ClipboardCheck — Card Requests */
export function ClipboardCheckIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" />
            <rect x="9" y="3" width="6" height="4" rx="1" />
            <polyline points="9 12 11 14 15 10" />
        </svg>
    );
}

/** Printer — Print Batches */
export function PrinterIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <polyline points="6 9 6 2 18 2 18 9" />
            <path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2" />
            <rect x="6" y="14" width="12" height="8" />
        </svg>
    );
}

/** QrCode — Card Verifications */
export function QrCodeIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
            <path d="M14 14h.01M18 14h.01M14 18h.01M18 18h.01M14 22h.01" />
        </svg>
    );
}

/** Handshake — Service Providers */
export function HandshakeIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M11 17a4 4 0 004-4V7h-2l-2 2-2-2H7v6a4 4 0 004 4z" />
            <path d="M9 7H5L2 10l3 3h2" />
            <path d="M15 7h4l3 3-3 3h-2" />
            <path d="M9 17l-3 3M15 17l3 3" />
        </svg>
    );
}

/** BadgeCheck — Entitlements */
export function BadgeCheckIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M12 2l2.09 6.26L20 10l-5.64 4.13L16.18 21 12 17.27 7.82 21l1.82-6.87L4 10l5.91-1.74L12 2z" />
            <polyline points="10 13 11.5 14.5 14.5 11.5" />
        </svg>
    );
}

/** ReceiptText — Service Transactions */
export function ReceiptTextIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1-2-1z" />
            <line x1="8" y1="10" x2="16" y2="10" />
            <line x1="8" y1="14" x2="16" y2="14" />
            <line x1="8" y1="18" x2="12" y2="18" />
        </svg>
    );
}

/** Key — Permissions */
export function KeyIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <circle cx="7.5" cy="15.5" r="5.5" />
            <path d="M21 2l-9.6 9.6" />
            <path d="M15.5 7.5l3 3L22 7l-3-3" />
        </svg>
    );
}

/** UserCog — Admin Users */
export function UserCogIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <circle cx="18" cy="15" r="3" />
            <path d="M18 12v-2" />
            <path d="M18 21v-1" />
            <path d="M15.27 13.5l-1.07-.63" />
            <path d="M21.8 16.5l-1.07-.63" />
            <path d="M15.27 16.5l-1.07.63" />
            <path d="M21.8 13.5l-1.07.63" />
            <path d="M14 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2" />
            <circle cx="9" cy="7" r="4" />
        </svg>
    );
}

/** TrendingUp — Grade Levels (progression/ranking) */
export function TrendingUpIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="m22 7-8.5 8.5-5-5L2 17" />
            <path d="M16 7h6v6" />
        </svg>
    );
}

/** HardHat — Occupations (work/trade category) */
export function HardHatIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M2 18a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1H3a1 1 0 0 0-1 1v2z" />
            <path d="M10 10V5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5" />
            <path d="M4 15v-3a6 6 0 0 1 6-6h0" />
            <path d="M14 6h0a6 6 0 0 1 6 6v3" />
        </svg>
    );
}

/** Activity — ISIC Activities (industrial/economic activity) */
export function ActivityIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
        </svg>
    );
}

/** ListOrdered — Organization Types (categorized list) */
export function ListOrderedIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M10 12H3" />
            <path d="M10 18H3" />
            <path d="M10 6H3" />
            <path d="M21 12h-6" />
            <path d="M15 10l2 2-2 2" />
            <path d="M21 6h-6" />
            <path d="M15 4l2 2-2 2" />
            <path d="M21 18h-6" />
            <path d="M15 16l2 2-2 2" />
        </svg>
    );
}

/** ClipboardList — Position Establishments */
export function ClipboardListIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2" />
            <rect x="9" y="3" width="6" height="4" rx="1" />
            <line x1="9" y1="12" x2="15" y2="12" />
            <line x1="9" y1="16" x2="13" y2="16" />
        </svg>
    );
}

/** Megaphone — Vacancy Announcements */
export function MegaphoneIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M3 11l19-9-9 19-2-8-8-2z" />
        </svg>
    );
}

/** Boxes — Organization Unit Types (grouped components) */
export function BoxesIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M2.97 12.92A2 2 0 0 0 2 14.63v3.24a2 2 0 0 0 .97 1.71l3 1.8a2 2 0 0 0 2.06 0L12 19v-5.5l-5-3-4.03 2.42Z" />
            <path d="m7 16.5-4.74-2.85" />
            <path d="m7 16.5 5-3" />
            <path d="M7 16.5v5.17" />
            <path d="M12 13.5V19l3.97 2.38a2 2 0 0 0 2.06 0l3-1.8a2 2 0 0 0 .97-1.71v-3.24a2 2 0 0 0-.97-1.71L17 10.5l-5 3Z" />
            <path d="m17 16.5-5-3" />
            <path d="m17 16.5 4.74-2.85" />
            <path d="M17 16.5v5.17" />
            <path d="M7.97 4.42A2 2 0 0 0 7 6.13v4.37l5 3 5-3V6.13a2 2 0 0 0-.97-1.71l-3-1.8a2 2 0 0 0-2.06 0l-3 1.8Z" />
            <path d="M12 8 7.26 5.15" />
            <path d="m12 8 4.74-2.85" />
            <path d="M12 13.5V8" />
        </svg>
    );
}

/** Message square — Service Feedback (client comments) */
export function MessageSquareIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
        </svg>
    );
}

/** Star — satisfaction ratings and feedback reports */
export function StarIcon(p: IconProps) {
    return (
        <svg {...base} {...p}>
            <path d="M12 17.75l-6.172 3.245 1.179-6.873-4.993-4.867 6.9-1.002L12 2l3.086 6.253 6.9 1.002-4.993 4.867 1.179 6.873z" />
        </svg>
    );
}
