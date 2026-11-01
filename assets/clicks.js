// Search Insights: counts clicks on search results that carry a `data-search-insights` token.
// No cookies, nothing is sent on page load.
(() => {
  const script = document.currentScript;
  const endpoint = script && script.dataset.url;

  if (!endpoint || !navigator.sendBeacon) {
    return;
  }

  const sent = new WeakSet();

  const send = (event) => {
    if (event.type === "auxclick" && event.button !== 1) {
      return;
    }

    const link = event.target.closest && event.target.closest("[data-search-insights]");

    if (!link || sent.has(link)) {
      return;
    }

    sent.add(link);
    navigator.sendBeacon(endpoint, new URLSearchParams({ token: link.dataset.searchInsights }));
  };

  document.addEventListener("click", send, true);
  document.addEventListener("auxclick", send, true);
})();
