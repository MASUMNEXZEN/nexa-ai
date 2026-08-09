
    const status = document.getElementById('status');
    async function nuke() {
      try {
        status.textContent = 'Unregistering Service Workers...';
        const regs = await navigator.serviceWorker.getRegistrations();
        await Promise.all(regs.map(r => r.unregister()));

        status.textContent = 'Clearing all caches...';
        const keys = await caches.keys();
        await Promise.all(keys.map(k => caches.delete(k)));

        status.textContent = 'Done! Loading fresh NexA AI...';
        setTimeout(() => {
          window.location.replace('/?nocache=' + Date.now());
        }, 600);
      } catch(e) {
        console.info('Cache cleanup was incomplete:', e.message);
        status.textContent = 'Redirecting...';
        setTimeout(() => window.location.replace('/?nocache=' + Date.now()), 800);
      }
    }
    nuke();
