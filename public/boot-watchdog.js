// Make module loading and a stalled first API request visible instead of
// leaving the initial spinner on screen indefinitely.
window.setTimeout(() => {
  const loader = document.getElementById('startup-loader');
  if (!loader?.isConnected) return;
  document.getElementById('app').innerHTML = `<main class="boot" role="alert"><h2>Start nicht abgeschlossen</h2><p>Die Web-App oder der Server hat innerhalb von 15 Sekunden nicht geantwortet. Prüft, ob alle Dateien aus <code>public/</code> hochgeladen wurden und ob <code>/api/status</code> erreichbar ist.</p><button class="button primary" type="button" id="startup-retry">Erneut versuchen</button></main>`;
  document.getElementById('startup-retry')?.addEventListener('click', () => location.reload());
}, 15000);
