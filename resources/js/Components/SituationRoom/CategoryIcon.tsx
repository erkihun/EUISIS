import type { JSX, SVGProps } from 'react';
import * as NavIcons from '@/Components/NavIcons';

type IconComponent = (props: SVGProps<SVGSVGElement>) => JSX.Element;

/** A category's icon, by the name the registry (SituationCategory::icon) gives it. */
export default function CategoryIcon({ name, ...props }: { name: string } & SVGProps<SVGSVGElement>) {
    const Icon = (NavIcons as unknown as Record<string, IconComponent | undefined>)[name] ?? NavIcons.LayoutDashboard;

    return <Icon aria-hidden="true" {...props} />;
}
