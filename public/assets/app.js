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

const townSearch = document.getElementById('search-town');
if (townSearch) {
  const town = document.getElementById('town');
  const matches = document.getElementById('weather-town');
  const lookup = document.getElementById('lookup-weather');
  const status = document.getElementById('weather-status');
  const date = document.getElementById('experienced_on');
  const fields = ['weather', 'temperature_c', 'pressure_hpa'];
  const provenance = ['weather_source','weather_date','weather_timezone'];
  let busy = false;
  const manual = () => { provenance.forEach(id => { document.getElementById(id).value = ''; }); status.textContent = 'Manual context. Values are saved as entered.'; };
  document.getElementById('manual-weather').addEventListener('click', manual);
  fields.forEach(id => document.getElementById(id).addEventListener('input', manual));
  town.addEventListener('input', () => { matches.replaceChildren(new Option('Search for this town', '')); lookup.disabled = true; manual(); });
  date.addEventListener('change', () => {
    if (document.getElementById('weather_source').value) {
      fields.forEach(id => { document.getElementById(id).value = ''; });
      provenance.forEach(id => { document.getElementById(id).value = ''; });
      status.textContent = 'Date changed. Look up weather again for the new date.';
    }
  });
  matches.addEventListener('change', () => { lookup.disabled = !matches.value || busy; });
  const requestWeather = async data => {
    const response = await fetch('weather-api.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-CSRF-Token':town.form.querySelector('[name="csrf"]').value}, body:JSON.stringify(data) });
    let body;
    try { body = await response.json(); } catch (_) {
      throw new Error(response.status >= 500
        ? `The local/server PHP process failed (HTTP ${response.status}). Check the Apache/PHP error log. On MAMP, restart the servers after updating Daywell.`
        : `Weather endpoint returned an unexpected response (HTTP ${response.status}). Check that weather-api.php is installed at this app’s URL, then reload.`);
    }
    if (!response.ok) throw new Error(body.error || 'Weather lookup failed.');
    return body;
  };
  const run = async callback => {
    if (busy) return;
    busy = true; townSearch.disabled = lookup.disabled = true;
    try { await callback(); } catch (error) { status.textContent = error.message; }
    finally { busy = false; townSearch.disabled = false; lookup.disabled = !matches.value; }
  };
  townSearch.addEventListener('click', () => run(async () => {
    status.textContent = 'Finding matching towns…';
    const data = await requestWeather({action:'search',town:town.value});
    matches.replaceChildren(new Option('Choose your town',''));
    data.towns.forEach(item => matches.add(new Option(item.label,item.id)));
    status.textContent = data.towns.length ? 'Choose the correct town, then fill weather.' : 'No matching towns found. Try another spelling.';
  }));
  lookup.addEventListener('click', () => run(async () => {
    const selectedDate = date.value;
    const selectedTown = town.value;
    status.textContent = 'Looking up weather for ' + selectedDate + '…';
    const data = await requestWeather({action:'weather',town_id:matches.value,date:selectedDate});
    if (date.value !== selectedDate || town.value !== selectedTown) throw new Error('Date or town changed during lookup. Please try again.');
    town.value = data.town;
    [...fields,...provenance].forEach(id => { document.getElementById(id).value = data[id]; });
    status.textContent = `${data.weather_source} · daily summary for ${data.weather_date} (${data.weather_timezone}). Ready to save.`;
  }));
}
