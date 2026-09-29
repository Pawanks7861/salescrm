<script setup>
import AppIcon from '@/Components/ui/AppIcon.vue';
import UiBadge from '@/Components/ui/UiBadge.vue';
import UiButton from '@/Components/ui/UiButton.vue';
import UiToggle from '@/Components/ui/UiToggle.vue';
import { useToast } from '@/Composables/useToast';
import { currentSubscription, disableBrowserPush, enableBrowserPush, isSupported, permissionState } from '@/notifications/browserPush';
import { registerFcm, unregisterFcm } from '@/notifications/fcm';
import { playTestSound, unlockAudio } from '@/notifications/sound';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const page = usePage();
const toast = useToast();
const push = computed(() => page.props.push ?? {});

const permission = ref(permissionState());
const subscribed = ref(false);
const busy = ref(false);
const sound = ref(Boolean(push.value.sound));

onMounted(async () => {
    try {
        const web = Boolean(await currentSubscription());
        const fcm = Boolean(push.value.fcm) && permissionState() === 'granted' && Boolean(push.value.browser);
        subscribed.value = web || fcm;
    } catch {
        subscribed.value = false;
    }
});

const status = computed(() => {
    if (!push.value.available) return { key: 'off', label: 'Turned off', color: 'slate', text: 'Browser notifications are turned off for this CRM by your administrator.' };
    if (!isSupported()) return { key: 'unsupported', label: 'Not supported', color: 'slate', text: 'This browser does not support notifications here. Use a current Chrome, Edge or Firefox over HTTPS.' };
    if (permission.value === 'denied') return { key: 'blocked', label: 'Blocked', color: 'red', text: 'Browser notifications are blocked. Enable them in your browser settings.' };
    if (push.value.browser && permission.value === 'granted' && subscribed.value) return { key: 'enabled', label: 'Enabled', color: 'emerald', text: 'This browser will show new leads and follow-up reminders when the CRM is not the active tab.' };
    return { key: 'disabled', label: 'Not enabled', color: 'amber', text: 'Receive notifications when the CRM isn’t the active tab.' };
});

const savePrefs = (data) => axios.put(route('profile.notifications'), data).then(() => router.reload({ only: ['push'] }));

function enableError(e) {
    const status = e?.response?.status;
    if (status === 409) return 'Browser notifications are turned off by your administrator.';
    if (status === 422) return 'This browser’s push service is not supported. Use Chrome, Edge or Firefox.';
    if (e?.name === 'AbortError' || e?.name === 'InvalidStateError' || e?.name === 'NotSupportedError') {
        return 'This browser has no push service available. Use Chrome, Edge or Firefox.';
    }
    return 'Browser notifications could not be enabled. Please try again.';
}

const enable = async () => {
    busy.value = true;
    try {
        unlockAudio();
        let result = 'granted';
        if (push.value.public_key) {
            result = await enableBrowserPush(push.value.public_key);
        } else if (!push.value.fcm) {
            result = 'unsupported';
        }
        if (result === 'granted' && push.value.fcm) {
            try {
                result = await registerFcm(push.value.fcm);
            } catch (e) {
                if (!push.value.public_key) throw e;
                toast.error('This browser could not register for Firebase notifications.');
            }
        }
        permission.value = permissionState();
        if (result === 'granted') {
            subscribed.value = true;
            await savePrefs({ browser_notifications_enabled: true });
            toast.success('Browser notifications enabled on this browser.');
        } else if (result === 'denied') {
            toast.error('Browser notifications are blocked. Enable them in your browser settings.');
        } else if (result === 'default') {
            toast.info('The browser permission prompt was dismissed. Click Enable to try again.');
        }
    } catch (e) {
        toast.error(enableError(e));
    } finally {
        busy.value = false;
    }
};

const disable = async () => {
    busy.value = true;
    try {
        await disableBrowserPush();
        if (push.value.fcm) await unregisterFcm(push.value.fcm);
        subscribed.value = false;
        await savePrefs({ browser_notifications_enabled: false });
        toast.success('Browser notifications turned off.');
    } catch {
        toast.error('Could not turn off browser notifications. Please try again.');
    } finally {
        busy.value = false;
    }
};

const toggleSound = async (value) => {
    sound.value = value;
    if (value) unlockAudio();
    try {
        await savePrefs({ notification_sound_enabled: value });
    } catch {
        sound.value = !value;
        toast.error('Could not save the sound preference.');
    }
};

const testSound = async (type) => {
    if (!(await playTestSound(type))) toast.error('This browser could not play the sound.');
};

const testDelay = ref(0);
const testing = ref(null);

const sendTest = async (event) => {
    testing.value = event;
    unlockAudio();
    try {
        await axios.post(route('profile.notifications.test'), { event, delay: testDelay.value });
        toast.success(testDelay.value ? `Test notification in ${testDelay.value} seconds — switch to another window or minimise the browser now.` : 'Test notification sent.');
    } catch (e) {
        toast.error(e?.response?.data?.message ?? 'The test notification could not be sent.');
    } finally {
        testing.value = null;
    }
};
</script>

<template>
    <section id="notification-preferences" class="panel scroll-mt-24" aria-labelledby="notification-preferences-title">
        <div class="panel-header"><h2 id="notification-preferences-title" class="panel-title">Notification preferences</h2></div>
        <div class="divide-y divide-slate-100">
            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-500">
                    <AppIcon :name="status.key === 'enabled' ? 'bell' : 'bell-off'" class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-sm font-semibold text-slate-900">Browser notifications</h3>
                        <UiBadge :color="status.color" dot>{{ status.label }}</UiBadge>
                    </div>
                    <p class="mt-1 text-sm text-slate-500" :class="{ 'text-red-600': status.key === 'blocked' }" role="status">{{ status.text }}</p>
                    <p class="mt-1 text-xs text-slate-400">New leads assigned to you and follow-up reminders. Notifications can appear on your device’s lock screen.</p>
                </div>
                <div class="shrink-0">
                    <UiButton v-if="status.key === 'disabled'" :loading="busy" icon="bell" @click="enable">Enable</UiButton>
                    <UiButton v-else-if="status.key === 'enabled'" variant="secondary" :loading="busy" @click="disable">Turn off</UiButton>
                </div>
            </div>
            <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-500">
                    <AppIcon :name="sound && push.sound_allowed ? 'speaker' : 'speaker-off'" class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-semibold text-slate-900">Sound notifications</h3>
                    <p class="mt-1 text-sm text-slate-500">Play a short chime for new leads and follow-up reminders. Other notifications stay silent.</p>
                    <p v-if="!push.sound_allowed" class="mt-1 text-xs text-amber-600">Notification sounds are turned off by your administrator.</p>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-3">
                    <UiToggle :model-value="sound" :disabled="!push.sound_allowed" :label="sound ? 'On' : 'Off'" @update:model-value="toggleSound" />
                    <UiButton variant="secondary" size="sm" icon="speaker" :disabled="!push.sound_allowed" @click="testSound('lead')">Test New Lead Sound</UiButton>
                    <UiButton variant="secondary" size="sm" icon="speaker" :disabled="!push.sound_allowed" @click="testSound('reminder')">Test Follow-up Sound</UiButton>
                </div>
            </div>
            <div v-if="status.key === 'enabled'" class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-500">
                    <AppIcon name="bell" class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <h3 class="text-sm font-semibold text-slate-900">Test browser notification</h3>
                    <p class="mt-1 text-sm text-slate-500">Sends a real notification to this account’s browsers through the server. Choose a delay to test while the CRM is in the background, minimised or closed.</p>
                    <label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-500">
                        Send
                        <select v-model.number="testDelay" class="rounded-lg border-slate-300 py-1 text-xs" aria-label="Test notification delay">
                            <option :value="0">now</option>
                            <option :value="10">in 10 seconds</option>
                            <option :value="30">in 30 seconds</option>
                        </select>
                    </label>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-3">
                    <UiButton variant="secondary" size="sm" :loading="testing === 'NEW_LEAD_ASSIGNED'" @click="sendTest('NEW_LEAD_ASSIGNED')">Test new lead</UiButton>
                    <UiButton variant="secondary" size="sm" :loading="testing === 'FOLLOWUP_REMINDER'" @click="sendTest('FOLLOWUP_REMINDER')">Test follow-up</UiButton>
                </div>
            </div>
        </div>
    </section>
</template>
