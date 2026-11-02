chrome.runtime.onMessage.addListener(message => {
  if (message?.type !== 'WUBACHAT_REFRESH_CHATS') return;

  window.dispatchEvent(new CustomEvent('wubachat:refresh-chats', {
    detail: JSON.stringify({ phone: message.phone || '' })
  }));
});
