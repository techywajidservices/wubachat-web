const PARAMOUNT_ORIGIN = 'https://sage.paramountservices.co.in';
const WUBACHAT_URL_PATTERNS = [
  'https://wubachat.com/*',
  'https://web.wubachat.com/*'
];

chrome.runtime.onMessage.addListener((message, sender) => {
  let senderOrigin = '';
  try {
    senderOrigin = new URL(sender.url || '').origin;
  } catch (error) {
    return;
  }

  if (
    message?.type !== 'WUBACHAT_DISPATCH_SUCCEEDED'
    || senderOrigin !== PARAMOUNT_ORIGIN
  ) {
    return;
  }

  const phone = String(message.phone || '').replace(/\D/g, '');
  if (!phone) return;

  chrome.tabs.query({ url: WUBACHAT_URL_PATTERNS }, tabs => {
    if (chrome.runtime.lastError) {
      console.error('Could not find Wubachat dashboard tabs:', chrome.runtime.lastError.message);
      return;
    }

    for (const tab of tabs) {
      if (!Number.isInteger(tab.id)) continue;

      chrome.tabs.sendMessage(
        tab.id,
        { type: 'WUBACHAT_REFRESH_CHATS', phone },
        () => {
          if (chrome.runtime.lastError) {
            console.warn('Could not notify Wubachat tab:', chrome.runtime.lastError.message);
          }
        }
      );
    }
  });
});
