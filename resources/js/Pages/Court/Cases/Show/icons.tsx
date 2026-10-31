import type { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement>;

const base = (props: IconProps, path: string) => (
    <svg className={props.className ?? 'h-4 w-4'} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true" {...props}>
        <path strokeLinecap="round" strokeLinejoin="round" d={path} />
    </svg>
);

/** The small outline icons the Blade case page drew inline. */
export const SummaryIcon = (p: IconProps) => base(p, 'M4 6h16M4 12h16M4 18h16');
export const DetailsIcon = (p: IconProps) => base(p, 'M9 12h6m-6 4h6M7 8h10M5 5h14v14H5z');
export const FilesIcon = (p: IconProps) => base(p, 'M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z');
export const CalendarIcon = (p: IconProps) => base(p, 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z');
export const ChatIcon = (p: IconProps) => base(p, 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z');
export const MailIcon = (p: IconProps) => base(p, 'M3 7l9 6 9-6M5 5h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z');
export const AuditIcon = (p: IconProps) => base(p, 'M9 17v-2m0 0V9m0 6h6m-6-4h6m2 8H7a2 2 0 01-2-2V7a2 2 0 012-2h10a2 2 0 012 2v10a2 2 0 01-2 2z');
export const ResponsesIcon = (p: IconProps) => base(p, 'M5 7h14M5 11h10M5 15h14');
export const RepliesIcon = (p: IconProps) => base(p, 'M8 10h8M8 14h5M6 4h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2z');
export const UserIcon = (p: IconProps) => base(p, 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z');
export const UsersIcon = (p: IconProps) => base(p, 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z');
export const WitnessesIcon = (p: IconProps) => base(p, 'M17 20h5v-2a3 3 0 0 0-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 0 1 5.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 0 1 9.288 0M15 7a3 3 0 1 1-6 0 3 3 0 0 1 6 0zm6 3a2 2 0 1 1-4 0 2 2 0 0 1 4 0z');
export const WarningIcon = (p: IconProps) => base(p, 'M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z');
export const CheckIcon = (p: IconProps) => base(p, 'M5 13l4 4L19 7');
export const ReturnIcon = (p: IconProps) => base(p, 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6');
export const CloseIcon = (p: IconProps) => base(p, 'M6 18L18 6M6 6l12 12');
export const PlusIcon = (p: IconProps) => base(p, 'M12 6v6m0 0v6m0-6h6m-6 0H6');
export const PencilIcon = (p: IconProps) => base(p, 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z');
export const TrashIcon = (p: IconProps) => base(p, 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16');
export const EyeIcon = (p: IconProps) => base(p, 'M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z');
export const PrintIcon = (p: IconProps) => base(p, 'M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z');
export const UploadIcon = (p: IconProps) => base(p, 'M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12');
export const SendIcon = (p: IconProps) => base(p, 'M12 19l9 2-9-18-9 18 9-2zm0 0v-8');
export const BackIcon = (p: IconProps) => base(p, 'M10 19l-7-7m0 0l7-7m-7 7h18');
export const ExternalIcon = (p: IconProps) => base(p, 'M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14');
export const LinkIcon = (p: IconProps) => base(p, 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1');
export const ChevronDownIcon = (p: IconProps) => base(p, 'M19 9l-7 7-7-7');
export const StatusIcon = (p: IconProps) => base(p, 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z');
export const NoteIcon = (p: IconProps) => base(p, 'M7 8h10M7 12h4M7 16h8M11 4h2a2 2 0 012 2v14h-4V6a2 2 0 012-2h2');
export const LetterIcon = (p: IconProps) => base(p, 'M12 20h9M12 4h9m-9 8h9M5 6h.01M5 12h.01M5 18h.01');
export const ComposeIcon = (p: IconProps) => base(p, 'M11 4h10M11 8h10M4 16h16M4 12h16M4 8h4M4 4h4');
export const ClockIcon = (p: IconProps) => base(p, 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z');
