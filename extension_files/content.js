(function initWubachatBridge() {
  function findData() {
    let vehicleNo = '', claimNo = '', workshopName = '', contactPhone = '';

    document.querySelectorAll('*').forEach(el => {
      let t = el.innerText || '';
      if (!vehicleNo && t.includes('VEHICLE NO:')) {
        let m = t.match(/VEHICLE NO:\s*([A-Z0-9]+)/i);
        if (m) vehicleNo = m[1].trim();
      }
      if (!claimNo && t.includes('CLAIM NO:')) {
        let m = t.match(/CLAIM NO:\s*([0-9]+)/i);
        if (m) claimNo = m[1].trim();
      }
    });

    document.querySelectorAll('input').forEach(inp => {
      let val = (inp.value || '').trim();
      if (!val) return;

      let placeholder = (inp.placeholder || '').toLowerCase();
      let parentText = (inp.closest('div') ? inp.closest('div').innerText : '').toLowerCase();

      if (!workshopName && (parentText.includes('contact person') || parentText.includes('workshop name') || placeholder.includes('person') || placeholder.includes('workshop'))) {
        workshopName = val;
      }

      let cleanDigits = val.replace(/[^0-9]/g, '');
      if (!contactPhone && cleanDigits.length === 10) {
        if (parentText.includes('phone') || parentText.includes('contact') || placeholder.includes('phone') || placeholder.includes('mobile')) {
          contactPhone = cleanDigits;
        }
      }
    });

    if (!contactPhone) {
      document.querySelectorAll('input').forEach(inp => {
        let clean = (inp.value || '').replace(/[^0-9]/g, '');
        if (clean.length === 10 && clean !== claimNo && !clean.startsWith('16340')) {
          contactPhone = clean;
        }
      });
    }

    return { vehicleNo, claimNo, workshopName, contactPhone };
  }

  function getLiveGeneratedLink() {
    let link = '';
    document.querySelectorAll('input').forEach(inp => {
      let val = (inp.value || '').trim();
      if (val.includes('api/initiate-link/') || val.includes('sage-be')) {
        link = val;
      }
    });
    return link;
  }

  function injectSendButton() {
    let genBtn = Array.from(document.querySelectorAll('button')).find(b => 
      b.innerText && b.innerText.trim().toLowerCase().includes('generate link')
    );

    if (!genBtn || document.getElementById('btn-wubachat-quick-send')) return;

    const sendBtn = document.createElement('button');
    sendBtn.id = 'btn-wubachat-quick-send';
    sendBtn.type = 'button';
    sendBtn.innerText = '⚡ Send via Wubachat';
    sendBtn.style.cssText = `
      background: #25D366 !important;
      color: #ffffff !important;
      font-weight: bold !important;
      border: none !important;
      padding: 9px 18px !important;
      border-radius: 6px !important;
      cursor: pointer !important;
      margin-left: 12px !important;
      font-size: 14px !important;
      display: inline-flex !important;
      align-items: center !important;
      box-shadow: 0 2px 6px rgba(0,0,0,0.18) !important;
    `;

    sendBtn.onclick = async (e) => {
      e.preventDefault();
      e.stopPropagation();

      const link = getLiveGeneratedLink();
      const data = findData();

      if (!link) {
        alert('Please click "Generate Link" first to generate the inspection link.');
        return;
      }
      if (!data.contactPhone) {
        alert('Contact Phone number not found. Please verify the contact details.');
        return;
      }

      // Check configured sender phone from extension popup
      let senderPhone = '';
      try {
        const stored = await chrome.storage.local.get(['wuba_sender_phone']);
        senderPhone = stored.wuba_sender_phone || '';
      } catch (eStorage) {}

      if (!senderPhone) {
        alert('Please click the Wubachat Extension icon in Chrome toolbar and save your registered mobile number first.');
        return;
      }

      sendBtn.innerText = 'Sending WhatsApp...';
      sendBtn.disabled = true;
      sendBtn.style.opacity = '0.7';

      try {
        const formData = new FormData();
        formData.append('recipient', data.contactPhone);
        formData.append('workshop_name', data.workshopName || 'Workshop');
        formData.append('claim_number', data.claimNo);
        formData.append('vehicle_number', data.vehicleNo);
        formData.append('inspection_link', link);
        formData.append('sender_phone', senderPhone);

        const response = await fetch('https://web.wubachat.com/api/quick_dispatch.php', {
          method: 'POST',
          body: formData
        });

        const res = await response.json();

        if (res.status) {
          sendBtn.innerText = 'Sent Successfully!';
          sendBtn.style.background = '#128C7E !important';
          alert(`Message Sent Successfully!\nRecipient: ${data.workshopName} (${data.contactPhone})\nClaim No: ${data.claimNo}`);
        } else {
          alert('Error: ' + res.message);
          sendBtn.innerText = '⚡ Send via Wubachat';
          sendBtn.disabled = false;
          sendBtn.style.opacity = '1';
        }
      } catch (err) {
        alert('Network Error: ' + err.message);
        sendBtn.innerText = '⚡ Send via Wubachat';
        sendBtn.disabled = false;
        sendBtn.style.opacity = '1';
      }
    };

    genBtn.parentNode.insertBefore(sendBtn, genBtn.nextSibling);
  }

  setInterval(injectSendButton, 800);
})();