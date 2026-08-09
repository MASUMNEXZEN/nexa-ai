
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/sw.js').catch((error) => console.info('Service worker registration unavailable:', error.message));
    }
