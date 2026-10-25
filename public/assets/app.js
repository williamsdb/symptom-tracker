'use strict';
const strength = document.getElementById('strength');
const output = document.getElementById('strength-output');
if (strength && output) strength.addEventListener('input', () => { output.value = `${strength.value} / 10`; });
document.querySelectorAll('form[data-confirm]').forEach(form => {
  form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});

const reminderForm = document.getElementById('reminder-form');
if (reminderForm) {
  const status = document.getElementById('reminder-status');
  const enable = document.getElementById('enable-reminders');
  const test = document.getElementById('test-reminder');
  const disable = document.getElementById('disable-reminders');
  const time = document.getElementById('reminder-time');
  const timezone = document.getElementById('reminder-timezone');
  const skip = document.getElementById('skip-logged');
  let registration;
  let subscription;
  let enabled = false;
  let busy = false;
  const message = (text, error = false) => {
    status.textContent = text;
    status.classList.toggle('error', error);
  };
  const controls = () => {
    enable.disabled = busy || !registration;
    test.disabled = busy || !enabled;
    disable.disabled = busy || !subscription;
  };
  const api = async (action, extra = {}) => {
    const response = await fetch('push-api.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': reminderForm.dataset.csrf },
      body: JSON.stringify({ action, endpoint: subscription?.endpoint, ...extra })
    });
    let data;
    try { data = await response.json(); } catch (_) { throw new Error('The server returned an unexpected response. Check your connection and server configuration.'); }
    if (!response.ok) throw new Error(data.error || 'Could not update reminders.');
    return data;
  };
  const publicKey = () => {
    const key = reminderForm.dataset.publicKey;
    const raw = atob(key.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - key.length % 4) % 4));
    return Uint8Array.from(raw, character => character.charCodeAt(0));
  };
  const initialise = async () => {
    if (!reminderForm.dataset.publicKey) return message('Reminders are awaiting server setup.');
    const ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (ios && !(window.matchMedia('(display-mode: standalone)').matches || navigator.standalone)) return message('Add Daywell to your Home Screen, then open it from that icon to enable reminders.');
    if (!window.isSecureContext || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return message('Notifications aren’t available here. Use a supported browser over HTTPS.', true);
    const detected = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (Array.from(timezone.options).some(option => option.value === detected)) timezone.value = detected;
    try {
      await navigator.serviceWorker.register('sw.js', { scope: './', updateViaCache: 'none' });
      registration = await navigator.serviceWorker.ready;
      subscription = await registration.pushManager.getSubscription();
      if (subscription) {
        const data = await api('status');
        if (data.subscription) {
          enabled = data.subscription.enabled;
          time.value = data.subscription.time;
          timezone.value = data.subscription.timezone;
          skip.checked = data.subscription.skip_logged;
          message(enabled ? `Reminders are on at ${time.value} (${timezone.value}).${data.subscription.last_error ? ' ' + data.subscription.last_error : ''}` : 'Reminders are off on this device.');
        } else message('Choose a time and enable reminders on this device.');
      } else message(Notification.permission === 'denied' ? 'Notifications are blocked. Allow them in your browser or iPhone notification settings, then reload.' : 'Choose your time, then enable reminders.');
    } catch (error) { message(error.message, true); }
    controls();
  };
  reminderForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !registration) return;
    busy = true; controls();
    try {
      // Request directly in the tap handler, before any network work (required on iOS).
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') throw new Error('Notifications were not allowed. You can change permission in your browser or iPhone notification settings.');
      subscription = await registration.pushManager.getSubscription();
      if (subscription) {
        const currentKey = subscription.options.applicationServerKey;
        const expectedKey = publicKey();
        if (currentKey && Array.from(new Uint8Array(currentKey)).join() !== Array.from(expectedKey).join()) {
          await api('disable');
          await subscription.unsubscribe(); subscription = null;
        }
      }
      subscription ||= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: publicKey() });
      const data = await api('save', { subscription: subscription.toJSON(), time: time.value, timezone: timezone.value, skip_logged: skip.checked });
      enabled = true; message(data.message + ` Daily at ${time.value} (${timezone.value}).`);
    } catch (error) { message(error.message, true); }
    finally { busy = false; controls(); }
  });
  test.addEventListener('click', async () => {
    if (busy) return;
    busy = true; controls();
    try { const data = await api('test'); message(data.message); }
    catch (error) { message(error.message, true); }
    finally { busy = false; controls(); }
  });
  disable.addEventListener('click', async () => {
    if (busy) return;
    busy = true; controls();
    try {
      const data = await api('disable');
      enabled = false;
      // Keep the subscription so it can be enabled again without another prompt.
      message(data.message);
    } catch (error) { message(error.message, true); }
    finally { busy = false; controls(); }
  });
  initialise();
}
