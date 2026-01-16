/**
 * AI Entity Index Admin UI Entry Point
 *
 * Mounts the React application to the WordPress admin container.
 */

import { createRoot } from 'react-dom/client';
import { StrictMode } from 'react';
import App from './App';
import './styles/tailwind.css';

/**
 * Initialize the admin UI when DOM is ready.
 */
const initAdmin = () => {
  const container = document.getElementById('vibe-ai-admin');

  if (!container) {
    // eslint-disable-next-line no-console
    console.error('AI Entity Index: Admin container #vibe-ai-admin not found');
    return;
  }

  const root = createRoot(container);

  root.render(
    <StrictMode>
      <App />
    </StrictMode>
  );
};

// Wait for DOM to be ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAdmin);
} else {
  initAdmin();
}
