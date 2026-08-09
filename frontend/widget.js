(function() {
  const styles = `
    @import url('/vendor/fonts.css');

    #nexa-chat-widget-btn {
      position: fixed;
      bottom: 24px;
      right: 24px;
      width: 64px;
      height: 64px;
      background: #11152A;
      border-radius: 50%;
      box-shadow: 0 8px 32px rgba(98, 91, 238, 0.4), inset 0 0 0 1px rgba(255,255,255,0.1);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 999999;
      transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
      border: none;
      outline: none;
      -webkit-tap-highlight-color: transparent;
    }

    #nexa-chat-widget-btn::before {
      content: '';
      position: absolute;
      inset: -4px;
      border-radius: 50%;
      background: linear-gradient(135deg, #625BEE, #4F46E5);
      z-index: -1;
      opacity: 0;
      transition: opacity 0.4s ease;
      filter: blur(8px);
    }

    #nexa-chat-widget-btn:hover {
      transform: translateY(-4px) scale(1.05);
      box-shadow: 0 12px 40px rgba(98, 91, 238, 0.6), inset 0 0 0 1px rgba(255,255,255,0.2);
    }
    #nexa-chat-widget-btn:hover::before {
      opacity: 1;
    }
    #nexa-chat-widget-btn:active {
      transform: translateY(0) scale(0.95);
    }

    /* SVG Icon Container Setup */
    #nexa-widget-icon-wrap {
      position: relative;
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    #nexa-widget-icon, #nexa-widget-close {
      position: absolute;
      transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
    }

    #nexa-widget-icon {
      fill: none;
      stroke: url(#nexa-grad);
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
      width: 28px;
      height: 28px;
      opacity: 1;
      transform: rotate(0deg) scale(1);
    }

    #nexa-widget-close {
      stroke: #ffffff;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
      width: 24px;
      height: 24px;
      opacity: 0;
      transform: rotate(-90deg) scale(0);
    }

    /* State toggles for icons */
    .nexa-widget-active #nexa-widget-icon {
      opacity: 0;
      transform: rotate(90deg) scale(0);
    }
    .nexa-widget-active #nexa-widget-close {
      opacity: 1;
      transform: rotate(0deg) scale(1);
    }

    /* Iframe Container */
    #nexa-chat-widget-container {
      position: fixed;
      bottom: 104px;
      right: 24px;
      width: 420px;
      height: 680px;
      max-width: calc(100vw - 48px);
      max-height: calc(100vh - 128px);
      background: #11152A;
      border-radius: 24px;
      box-shadow: 0 24px 80px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255,255,255,0.08);
      z-index: 999998;
      overflow: hidden;
      opacity: 0;
      pointer-events: none;
      transform: translateY(20px) scale(0.96);
      transform-origin: bottom right;
      transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
    }
    
    #nexa-chat-widget-container.nexa-open {
      opacity: 1;
      pointer-events: auto;
      transform: translateY(0) scale(1);
    }

    #nexa-chat-widget-iframe {
      width: 100%;
      height: 100%;
      border: none;
      background: #11152A;
    }

    /* Tooltip */
    #nexa-chat-tooltip {
      position: absolute;
      right: calc(100% + 16px);
      top: 50%;
      transform: translateY(-50%) translateX(10px);
      background: #fff;
      color: #11152A;
      padding: 8px 16px;
      border-radius: 20px;
      font-family: 'Outfit', system-ui, sans-serif;
      font-size: 14px;
      font-weight: 600;
      white-space: nowrap;
      box-shadow: 0 4px 16px rgba(0,0,0,0.15);
      opacity: 0;
      pointer-events: none;
      transition: all 0.3s cubic-bezier(0.16,1,0.3,1);
    }
    #nexa-chat-widget-btn:hover #nexa-chat-tooltip {
      opacity: 1;
      transform: translateY(-50%) translateX(0);
    }
    .nexa-widget-active #nexa-chat-tooltip {
      display: none !important;
    }

    /* Mobile handling */
    @media (max-width: 480px) {
      #nexa-chat-widget-btn {
        bottom: 16px;
        right: 16px;
        width: 56px;
        height: 56px;
      }
      #nexa-chat-widget-container {
        bottom: 0;
        right: 0;
        width: 100dvw;
        height: 100dvh;
        max-width: none;
        max-height: none;
        border-radius: 0;
        transform-origin: center;
        transform: translateY(20px);
      }
      #nexa-chat-tooltip { display: none; }
    }
  `;

  // Inject Styles
  const styleSheet = document.createElement("style");
  styleSheet.type = "text/css";
  styleSheet.innerText = styles;
  document.head.appendChild(styleSheet);

  // SVG Definitions for Gradients
  const svgDefs = `
    <svg width="0" height="0" style="position:absolute;visibility:hidden;">
      <defs>
        <linearGradient id="nexa-grad" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stop-color="#4F46E5" />
          <stop offset="100%" stop-color="#625BEE" />
        </linearGradient>
      </defs>
    </svg>
  `;
  document.body.insertAdjacentHTML('beforeend', svgDefs);

  // Create Container
  const container = document.createElement("div");
  container.id = "nexa-chat-widget-container";
  
  // Create Iframe ?app=true ensures it removes the top header inside the app for a clean widget UI
  const iframe = document.createElement("iframe");
  iframe.id = "nexa-chat-widget-iframe";
  iframe.src = "https://ai.nexzen.live/?app=true"; 
  container.appendChild(iframe);
  document.body.appendChild(container);

  // Create Button
  const btn = document.createElement("button");
  btn.id = "nexa-chat-widget-btn";
  btn.setAttribute("aria-label", "Toggle AI Chat");
  
  // Premium SVG Sparkle/AI icon mapping to the platform's brand
  btn.innerHTML = `
    <div id="nexa-chat-tooltip">Ask NexA AI</div>
    <div id="nexa-widget-icon-wrap">
      <svg id="nexa-widget-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
        <path d="M8.5 10.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/>
        <path d="M15.5 10.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/>
        <path d="M12 16a4.5 4.5 0 0 0 3.5-1.5"/>
      </svg>
      <svg id="nexa-widget-close" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
        <line x1="18" y1="6" x2="6" y2="18"/>
        <line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </div>
  `;
  document.body.appendChild(btn);

  let isOpen = false;

  btn.addEventListener("click", () => {
    isOpen = !isOpen;
    if (isOpen) {
      container.classList.add("nexa-open");
      btn.classList.add("nexa-widget-active");
      
      // Post message to iframe to focus input if ready
      if(iframe.contentWindow) {
        iframe.contentWindow.postMessage('nexa-widget-opened', '*');
      }
    } else {
      container.classList.remove("nexa-open");
      btn.classList.remove("nexa-widget-active");
    }
  });

  // Listen for messages from iframe
  window.addEventListener('message', (e) => {
    // Basic security check: verify origin if needed in prod, here we allow all for easy embed
    if (e.data === 'nexa-close-widget' && isOpen) {
       btn.click(); // trigger smooth close animation
    }
  });

})();
