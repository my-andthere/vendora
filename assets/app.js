// Countdown SLA realtime.
// elapsed=1 -> tampilkan durasi lewat (LEWAT SLA) alih-alih sisa waktu.
function updateCountdowns() {
    document.querySelectorAll('.sla-countdown').forEach(function (el) {
        var diff = Math.floor((new Date(el.dataset.deadline) - new Date()) / 1000);
        var abs = Math.abs(diff);
        var h = Math.floor(abs / 3600), m = Math.floor((abs % 3600) / 60), s = abs % 60;
        var txt = (h ? h + 'j ' : '') + String(m).padStart(2, '0') + 'm ' + String(s).padStart(2, '0') + 'd';
        var over = diff < 0;
        el.className = 'badge sla-countdown ' + (over ? 'bg-danger' : (diff < 1800 ? 'bg-warning text-dark' : 'bg-light text-dark border'));
        el.textContent = over ? 'Lewat SLA \u00b7 ' + txt : 'Sisa ' + txt;
    });
}
updateCountdowns();
setInterval(updateCountdowns, 1000);

// Timer waktu pengerjaan (berjalan sampai tiket PERMANENT)
function updateWorkTimers() {
    var now = Date.now();
    document.querySelectorAll('.work-timer[data-start]').forEach(function (el) {
        var start = new Date(el.dataset.start).getTime();
        var end = el.dataset.end ? new Date(el.dataset.end).getTime() : now;
        var sec = Math.max(0, Math.floor((end - start) / 1000));
        var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
        var txt = (h ? h + 'j ' : '') + String(m).padStart(2, '0') + 'm ' + String(s).padStart(2, '0') + 'd';
        el.textContent = '⏱ ' + txt;
    });
}
updateWorkTimers();
setInterval(updateWorkTimers, 1000);

// Konfirmasi aksi
document.addEventListener('submit', function (ev) {
    var msg = ev.target.dataset.confirm;
    if (msg && !confirm(msg)) ev.preventDefault();
});
