import { useState } from "react";
import { router } from "@inertiajs/react";
import LocalizedDateDisplay from "@/Components/Calendar/LocalizedDateDisplay";
import {
    ClockIcon,
    GlobeIcon,
    HistoryIcon,
    LogOut,
    MonitorIcon,
    SmartphoneIcon,
    TabletIcon,
} from "@/Components/Icons";
import {
    Alert,
    Button,
    TBody,
    THead,
    Table,
    Td,
    Th,
    Tr,
    badgeClassName,
} from "@/Components/ui";
import { useConfirm } from "@/hooks/useConfirm";
import { useLocale } from "@/hooks/useLocale";
import { formatRelative } from "@/lib/relativeTime";
import SectionCard from "./SectionCard";
import type { DeviceKind, DeviceSession, DevicesInfo, SignIn } from "./types";

function DeviceGlyph({
    kind,
    className,
}: {
    kind: DeviceKind;
    className?: string;
}) {
    const Icon =
        kind === "phone"
            ? SmartphoneIcon
            : kind === "tablet"
              ? TabletIcon
              : kind === "desktop"
                ? MonitorIcon
                : GlobeIcon;

    return <Icon className={className} aria-hidden="true" />;
}

/**
 * The Devices tab: every live session of the account (from the sessions
 * table) with a way to end any other one, and the last sign-ins from the
 * audit log. Ending a session only deletes its row; the browser finds out on
 * its next request (docs/session-management.md §6).
 */
export default function DevicesSection({ devices }: { devices: DevicesInfo }) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    // The session id being ended, or 'others'; drives the one spinner that applies.
    const [busy, setBusy] = useState<string | null>(null);

    const others = devices.sessions.filter((session) => !session.current);

    // "Chrome · Windows": the middle dot keeps the label right in both languages.
    const deviceLabel = (item: Pick<SignIn, "browser" | "os">) =>
        [item.browser, item.os].filter(Boolean).join(" · ") ||
        t("profile.unknownDevice");

    async function signOut(session: DeviceSession) {
        const { confirmed } = await confirm({
            title: t("profile.signOutDeviceTitle"),
            description: t("profile.signOutDeviceBody"),
            confirmLabel: t("profile.signOutDevice"),
            cancelLabel: t("common.cancel"),
            variant: "warning",
        });
        if (!confirmed) return;

        router.delete(route("profile.devices.destroy", session.id), {
            preserveScroll: true,
            onStart: () => setBusy(session.id),
            onFinish: () => setBusy(null),
        });
    }

    async function signOutOthers() {
        const { confirmed } = await confirm({
            title: t("profile.signOutOthersTitle"),
            description: t("profile.signOutOthersBody"),
            confirmLabel: t("profile.signOutOthers"),
            cancelLabel: t("common.cancel"),
            variant: "warning",
        });
        if (!confirmed) return;

        router.delete(route("profile.devices.destroy-others"), {
            preserveScroll: true,
            onStart: () => setBusy("others"),
            onFinish: () => setBusy(null),
        });
    }

    return (
        <div className="space-y-6">
            <SectionCard
                id="profile-devices-title"
                icon={<MonitorIcon className="h-4.5 w-4.5" />}
                title={t("profile.devices")}
                description={t("profile.devicesHelp")}
                actions={
                    devices.supported && (
                        <Button
                            variant="outline"
                            icon={<LogOut className="h-4 w-4" />}
                            disabled={others.length === 0}
                            loading={busy === "others"}
                            onClick={signOutOthers}
                        >
                            {t("profile.signOutOthers")}
                        </Button>
                    )
                }
                flush
            >
                {!devices.supported ? (
                    <div className="p-(--card-padding)">
                        <Alert tone="info">
                            {t("profile.devicesUnavailable")}
                        </Alert>
                    </div>
                ) : (
                    <>
                        <ul className="divide-y divide-(--app-border)">
                            {devices.sessions.map((session) => (
                                <li
                                    key={session.id}
                                    className="flex flex-wrap items-center gap-4 px-(--card-padding) py-4"
                                >
                                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-panel bg-(--app-surface-muted) text-(--app-foreground)">
                                        <DeviceGlyph
                                            kind={session.device}
                                            className="h-5 w-5"
                                        />
                                    </span>
                                    <div className="min-w-0 flex-1 basis-64">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold text-(--app-foreground)">
                                                {deviceLabel(session)}
                                            </span>
                                            {session.current && (
                                                <span
                                                    className={badgeClassName(
                                                        "info",
                                                    )}
                                                >
                                                    {t("profile.thisDevice")}
                                                </span>
                                            )}
                                        </div>
                                        <p className="mt-1 flex flex-wrap gap-x-3.5 gap-y-1 text-helper text-(--app-muted-foreground)">
                                            {session.ip_address && (
                                                <span className="inline-flex items-center gap-1.5 tabular-nums">
                                                    <GlobeIcon
                                                        className="h-3.5 w-3.5"
                                                        aria-hidden="true"
                                                    />
                                                    {session.ip_address}
                                                </span>
                                            )}
                                            <span className="inline-flex items-center gap-1.5">
                                                <ClockIcon
                                                    className="h-3.5 w-3.5"
                                                    aria-hidden="true"
                                                />
                                                {session.current
                                                    ? t("profile.activeNow")
                                                    : formatRelative(
                                                          session.last_active_at,
                                                          locale,
                                                      )}
                                            </span>
                                        </p>
                                    </div>
                                    {session.current ? (
                                        <span className="text-helper text-(--app-muted-foreground)">
                                            {t("profile.currentDeviceHint")}
                                        </span>
                                    ) : (
                                        <Button
                                            variant="destructiveOutline"
                                            size="sm"
                                            loading={busy === session.id}
                                            onClick={() => signOut(session)}
                                        >
                                            {t("profile.signOutDevice")}
                                        </Button>
                                    )}
                                </li>
                            ))}
                            {others.length === 0 && (
                                <li className="px-(--card-padding) py-4 text-sm text-(--app-muted-foreground)">
                                    {t("profile.noOtherDevices")}
                                </li>
                            )}
                        </ul>
                        <p className="border-t border-(--app-border) px-(--card-padding) py-3 text-helper text-(--app-muted-foreground)">
                            {t("profile.devicesFootnote")}
                        </p>
                    </>
                )}
            </SectionCard>

            <SectionCard
                id="profile-sign-ins-title"
                icon={<HistoryIcon className="h-4.5 w-4.5" />}
                title={t("profile.recentSignIns")}
                description={t("profile.recentSignInsHelp")}
                flush
            >
                {devices.recent_sign_ins.length === 0 ? (
                    <p className="p-(--card-padding) text-sm text-(--app-muted-foreground)">
                        {t("profile.noRecentSignIns")}
                    </p>
                ) : (
                    <Table>
                        <THead>
                            <Tr>
                                <Th>{t("profile.when")}</Th>
                                <Th>{t("profile.device")}</Th>
                                <Th>{t("profile.ipAddress")}</Th>
                            </Tr>
                        </THead>
                        <TBody>
                            {devices.recent_sign_ins.map((signIn, index) => (
                                <Tr key={`${signIn.at}-${index}`}>
                                    <Td className="whitespace-nowrap">
                                        <LocalizedDateDisplay
                                            value={signIn.at}
                                            withTime
                                        />
                                    </Td>
                                    <Td>
                                        <span className="inline-flex items-center gap-2">
                                            <DeviceGlyph
                                                kind={signIn.device}
                                                className="h-4 w-4 shrink-0 text-(--app-muted-foreground)"
                                            />
                                            {deviceLabel(signIn)}
                                        </span>
                                    </Td>
                                    <Td muted className="tabular-nums">
                                        {signIn.ip_address ?? "—"}
                                    </Td>
                                </Tr>
                            ))}
                        </TBody>
                    </Table>
                )}
            </SectionCard>
        </div>
    );
}
