import type { ComponentType, SVGProps } from 'react';
import * as NavIcons from '@/Components/NavIcons';

type IconComponent = ComponentType<SVGProps<SVGSVGElement>>;

/** Resolves an IntelligenceArea::icon() name to the NavIcons component; unknown names fall back to the dashboard icon. */
export default function AreaIcon({ name, className }: { name: string; className?: string }) {
    const icons = NavIcons as unknown as Record<string, IconComponent | undefined>;
    const Icon = icons[name] ?? NavIcons.LayoutDashboard;

    return <Icon className={className} aria-hidden="true" />;
}
