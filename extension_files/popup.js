document.addEventListener('DOMContentLoaded', () => {
  const phoneInput = document.getElementById('userPhone');
  const saveBtn = document.getElementById('saveBtn');
  const statusEl = document.getElementById('status');

  // Pehle se saved number load karein
  chrome.storage.local.get(['wuba_sender_phone'], (res) => {
    if (res.wuba_sender_phone) {
      phoneInput.value = res.wuba_sender_phone;
    }
  });

  // Save button click par number store karein
  saveBtn.addEventListener('click', () => {
    const rawVal = phoneInput.value.trim();
    const cleanNum = rawVal.replace(/[^0-9]/g, '');

    if (cleanNum.length < 10) {
      alert('Please enter a valid 10-digit mobile number.');
      return;
    }

    const tenDigit = cleanNum.slice(-10);

    chrome.storage.local.set({ wuba_sender_phone: tenDigit }, () => {
      statusEl.style.display = 'block';
      setTimeout(() => {
        statusEl.style.display = 'none';
        window.close();
      }, 1000);
    });
  });
});