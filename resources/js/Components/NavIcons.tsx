/**
 * Icons of the admin shell (sidebar, header, toolbar): the Solar icon set, linear weight,
 * re-exported under the names the shell already uses so pages and tests keep their imports.
 * Solar icons are by 480 Design (CC BY 4.0), shipped through @solar-icons/react (MIT).
 * Add an icon here, never from the package directly, so the shell stays on one weight.
 */
import type { SVGProps } from 'react';
import { AltArrowDownIcon as SolarAltArrowDownIcon } from '@solar-icons/react/linear/alt-arrow-down';
import { AltArrowLeftIcon as SolarAltArrowLeftIcon } from '@solar-icons/react/linear/alt-arrow-left';
import { AltArrowRightIcon as SolarAltArrowRightIcon } from '@solar-icons/react/linear/alt-arrow-right';
import { BellIcon as SolarBellIcon } from '@solar-icons/react/linear/bell';
import { BillListIcon as SolarBillListIcon } from '@solar-icons/react/linear/bill-list';
import { BoxMinimalisticIcon as SolarBoxMinimalisticIcon } from '@solar-icons/react/linear/box-minimalistic';
import { BranchingPathsDownIcon as SolarBranchingPathsDownIcon } from '@solar-icons/react/linear/branching-paths-down';
import { Buildings2Icon as SolarBuildings2Icon } from '@solar-icons/react/linear/buildings-2';
import { CalendarIcon as SolarCalendarIcon } from '@solar-icons/react/linear/calendar';
import { CameraMinimalisticIcon as SolarCameraMinimalisticIcon } from '@solar-icons/react/linear/camera-minimalistic';
import { CardIcon as SolarCardIcon } from '@solar-icons/react/linear/card';
import { CaseMinimalisticIcon as SolarCaseMinimalisticIcon } from '@solar-icons/react/linear/case-minimalistic';
import { ChatSquareDotsIcon as SolarChatSquareDotsIcon } from '@solar-icons/react/linear/chat-square-dots';
import { ClipboardCheckIcon as SolarClipboardCheckIcon } from '@solar-icons/react/linear/clipboard-check';
import { ClipboardListIcon as SolarClipboardListIcon } from '@solar-icons/react/linear/clipboard-list';
import { ClockCircleIcon as SolarClockCircleIcon } from '@solar-icons/react/linear/clock-circle';
import { CloseCircleIcon as SolarCloseCircleIcon } from '@solar-icons/react/linear/close-circle';
import { DangerTriangleIcon as SolarDangerTriangleIcon } from '@solar-icons/react/linear/danger-triangle';
import { DocumentTextIcon as SolarDocumentTextIcon } from '@solar-icons/react/linear/document-text';
import { FileChartIcon as SolarFileChartIcon } from '@solar-icons/react/linear/file-chart';
import { GraphUpIcon as SolarGraphUpIcon } from '@solar-icons/react/linear/graph-up';
import { HamburgerMenuIcon as SolarHamburgerMenuIcon } from '@solar-icons/react/linear/hamburger-menu';
import { HandShakeIcon as SolarHandShakeIcon } from '@solar-icons/react/linear/hand-shake';
import { HashtagIcon as SolarHashtagIcon } from '@solar-icons/react/linear/hashtag';
import { HistoryIcon as SolarHistoryIcon } from '@solar-icons/react/linear/history';
import { InboxIcon as SolarInboxIcon } from '@solar-icons/react/linear/inbox';
import { KeyMinimalisticIcon as SolarKeyMinimalisticIcon } from '@solar-icons/react/linear/key-minimalistic';
import { LayersMinimalisticIcon as SolarLayersMinimalisticIcon } from '@solar-icons/react/linear/layers-minimalistic';
import { LetterIcon as SolarLetterIcon } from '@solar-icons/react/linear/letter';
import { Logout2Icon as SolarLogout2Icon } from '@solar-icons/react/linear/logout-2';
import { MagnifierIcon as SolarMagnifierIcon } from '@solar-icons/react/linear/magnifier';
import { MapPointIcon as SolarMapPointIcon } from '@solar-icons/react/linear/map-point';
import { MicrophoneIcon as SolarMicrophoneIcon } from '@solar-icons/react/linear/microphone';
import { MoonIcon as SolarMoonIcon } from '@solar-icons/react/linear/moon';
import { PhoneIcon as SolarPhoneIcon } from '@solar-icons/react/linear/phone';
import { PulseIcon as SolarPulseIcon } from '@solar-icons/react/linear/pulse';
import { QrCodeIcon as SolarQrCodeIcon } from '@solar-icons/react/linear/qr-code';
import { QuestionCircleIcon as SolarQuestionCircleIcon } from '@solar-icons/react/linear/question-circle';
import { Routing2Icon as SolarRouting2Icon } from '@solar-icons/react/linear/routing-2';
import { ScaleIcon as SolarScaleIcon } from '@solar-icons/react/linear/scale';
import { ScannerIcon as SolarScannerIcon } from '@solar-icons/react/linear/scanner';
import { Server2Icon as SolarServer2Icon } from '@solar-icons/react/linear/server-2';
import { SettingsIcon as SolarSettingsIcon } from '@solar-icons/react/linear/settings';
import { ShieldCheckIcon as SolarShieldCheckIcon } from '@solar-icons/react/linear/shield-check';
import { SidebarMinimalisticIcon as SolarSidebarMinimalisticIcon } from '@solar-icons/react/linear/sidebar-minimalistic';
import { SpeakerMinimalisticIcon as SolarSpeakerMinimalisticIcon } from '@solar-icons/react/linear/speaker-minimalistic';
import { StarIcon as SolarStarIcon } from '@solar-icons/react/linear/star';
import { StreetsMapPointIcon as SolarStreetsMapPointIcon } from '@solar-icons/react/linear/streets-map-point';
import { StructureIcon as SolarStructureIcon } from '@solar-icons/react/linear/structure';
import { SunIcon as SolarSunIcon } from '@solar-icons/react/linear/sun';
import { TagIcon as SolarTagIcon } from '@solar-icons/react/linear/tag';
import { TransferHorizontalIcon as SolarTransferHorizontalIcon } from '@solar-icons/react/linear/transfer-horizontal';
import { TrashBinMinimalisticIcon as SolarTrashBinMinimalisticIcon } from '@solar-icons/react/linear/trash-bin-minimalistic';
import { UserIdIcon as SolarUserIdIcon } from '@solar-icons/react/linear/user-id';
import { UserRoundedIcon as SolarUserRoundedIcon } from '@solar-icons/react/linear/user-rounded';
import { UsersGroupRoundedIcon as SolarUsersGroupRoundedIcon } from '@solar-icons/react/linear/users-group-rounded';
import { VerifiedCheckIcon as SolarVerifiedCheckIcon } from '@solar-icons/react/linear/verified-check';
import { Widget5Icon as SolarWidget5Icon } from '@solar-icons/react/linear/widget-5';
import { VideocameraRecordIcon as SolarVideocameraRecordIcon } from '@solar-icons/react/linear/videocamera-record';

export type NavIcon = (props: Omit<SVGProps<SVGSVGElement>, 'ref'>) => JSX.Element;

/** Solar icons render 24px by default; the shell sizes them with classes, like the hand-drawn set they replace. */
function adapt(Icon: typeof SolarWidget5Icon): NavIcon {
    return function SolarIcon(props) {
        return <Icon strokeWidth={1.5} {...props} />;
    };
}

export const LayoutDashboard = adapt(SolarWidget5Icon);
export const Building2 = adapt(SolarBuildings2Icon);
export const Users = adapt(SolarUsersGroupRoundedIcon);
export const CreditCard = adapt(SolarCardIcon);
export const Layers = adapt(SolarLayersMinimalisticIcon);
export const ScrollText = adapt(SolarDocumentTextIcon);
export const X = adapt(SolarCloseCircleIcon);
export const SettingsIcon = adapt(SolarSettingsIcon);
export const ShieldCheck = adapt(SolarShieldCheckIcon);
export const Briefcase = adapt(SolarCaseMinimalisticIcon);
export const TrashIcon = adapt(SolarTrashBinMinimalisticIcon);
export const TagsIcon = adapt(SolarTagIcon);
export const GitBranchIcon = adapt(SolarBranchingPathsDownIcon);
export const HashIcon = adapt(SolarHashtagIcon);
export const GitForkIcon = adapt(SolarStructureIcon);
export const ArrowLeftRightIcon = adapt(SolarTransferHorizontalIcon);
export const ClipboardCheckIcon = adapt(SolarClipboardCheckIcon);
export const ClipboardListIcon = adapt(SolarClipboardListIcon);
export const MegaphoneIcon = adapt(SolarSpeakerMinimalisticIcon);
export const Inbox = adapt(SolarInboxIcon);
export const BadgeCheckIcon = adapt(SolarVerifiedCheckIcon);
export const ReceiptTextIcon = adapt(SolarBillListIcon);
export const HandshakeIcon = adapt(SolarHandShakeIcon);
export const KeyIcon = adapt(SolarKeyMinimalisticIcon);
export const UserCogIcon = adapt(SolarUserIdIcon);
export const TrendingUpIcon = adapt(SolarGraphUpIcon);
export const HardHatIcon = adapt(SolarStreetsMapPointIcon);
export const ActivityIcon = adapt(SolarPulseIcon);
export const BoxesIcon = adapt(SolarBoxMinimalisticIcon);
export const QrCodeIcon = adapt(SolarQrCodeIcon);
export const UserIcon = adapt(SolarUserRoundedIcon);
export const NetworkIcon = adapt(SolarRouting2Icon);
export const MessageSquareIcon = adapt(SolarChatSquareDotsIcon);
export const StarIcon = adapt(SolarStarIcon);
export const NfcIcon = adapt(SolarScannerIcon);
export const RouterIcon = adapt(SolarServer2Icon);
export const ScaleIcon = adapt(SolarScaleIcon);
export const SearchIcon = adapt(SolarMagnifierIcon);
export const ChevronRight = adapt(SolarAltArrowRightIcon);
export const ChevronDown = adapt(SolarAltArrowDownIcon);
export const ChevronLeft = adapt(SolarAltArrowLeftIcon);
export const CalendarIcon = adapt(SolarCalendarIcon);
export const HistoryIcon = adapt(SolarHistoryIcon);
export const AlertTriangle = adapt(SolarDangerTriangleIcon);
export const ClockIcon = adapt(SolarClockCircleIcon);
export const MailIcon = adapt(SolarLetterIcon);
export const FileChartIcon = adapt(SolarFileChartIcon);
export const MapPinned = adapt(SolarMapPointIcon);
export const PhoneIcon = adapt(SolarPhoneIcon);
/** Evidence kinds on the public Tip-off and Complaint forms. */
export const PhotoIcon = adapt(SolarCameraMinimalisticIcon);
export const VideoIcon = adapt(SolarVideocameraRecordIcon);
export const MicIcon = adapt(SolarMicrophoneIcon);
export const MenuIcon = adapt(SolarHamburgerMenuIcon);
export const LogOut = adapt(SolarLogout2Icon);
export const Sun = adapt(SolarSunIcon);
export const Moon = adapt(SolarMoonIcon);
export const BellIcon = adapt(SolarBellIcon);
export const SidebarIcon = adapt(SolarSidebarMinimalisticIcon);
export const HelpIcon = adapt(SolarQuestionCircleIcon);

