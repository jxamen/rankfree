const mode = document.getElementById('mode');
chrome.storage.local.get('rfSerpMode').then(s => { mode.value = s.rfSerpMode === 'request' ? 'request' : 'tab'; });
mode.addEventListener('change', async () => {
  await chrome.storage.local.set({ rfSerpMode: mode.value });
  document.getElementById('status').textContent = '저장했습니다. 다음 수집부터 적용합니다.';
});
