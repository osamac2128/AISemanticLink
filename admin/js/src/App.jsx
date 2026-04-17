/**
 * AI Entity Index Admin App
 *
 * Main application component with routing and layout.
 */

import { Suspense, lazy, useCallback, useState } from 'react';
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import Header from './components/Layout/Header';
import Sidebar from './components/Layout/Sidebar';

const Dashboard = lazy( () => import( './components/Dashboard' ) );
const EntityManager = lazy( () => import( './components/EntityManager' ) );
const Settings = lazy( () => import( './components/Settings' ) );
const ActivityLog = lazy( () => import( './components/ActivityLog' ) );
const KnowledgeBase = lazy( () => import( './components/KnowledgeBase' ) );

/**
 * Global data from WordPress (localized via wp_localize_script).
 * @type {{apiUrl: string, nonce: string, adminUrl: string, pluginUrl: string, version: string, pollingInterval: number}}
 */
const vibeAiData = window.vibeAiData || {
	apiUrl: '/wp-json/vibe-ai/v1',
	nonce: '',
	adminUrl: '/wp-admin/',
	pluginUrl: '',
	version: '1.0.0',
	pollingInterval: 2000,
};

// Create a QueryClient instance for React Query
const queryClient = new QueryClient( {
	defaultOptions: {
		queries: {
			staleTime: 5 * 60 * 1000, // 5 minutes
			retry: 1,
			refetchOnWindowFocus: false,
		},
	},
} );

function RouteFallback() {
	return (
		<div className="flex min-h-[40vh] items-center justify-center p-8">
			<div className="rounded-lg border border-slate-200 bg-white px-5 py-4 text-sm text-slate-600 shadow-sm">
				Loading admin view...
			</div>
		</div>
	);
}

/**
 * Main App Component
 *
 * Provides routing and layout structure for the admin interface.
 * Uses HashRouter for compatibility with WordPress admin.
 */
export default function App() {
	const [ sidebarCollapsed, setSidebarCollapsed ] = useState( false );

	const toggleSidebar = useCallback( () => {
		setSidebarCollapsed( ( prev ) => ! prev );
	}, [] );

	return (
		<QueryClientProvider client={ queryClient }>
			<HashRouter>
				<div
					id="vibe-ai-admin"
					className="flex flex-col min-h-screen bg-slate-50"
				>
					{ /* Header */ }
					<Header
						onToggleSidebar={ toggleSidebar }
						vibeAiData={ vibeAiData }
					/>

					{ /* Main content area */ }
					<div className="flex flex-1">
						{ /* Sidebar navigation */ }
						<Sidebar collapsed={ sidebarCollapsed } />

						{ /* Page content */ }
						<main
							className={ `flex-1 transition-all duration-300 ${
								sidebarCollapsed ? 'ml-16' : 'ml-56'
							}` }
						>
							<Suspense fallback={ <RouteFallback /> }>
								<Routes>
									<Route
										path="/"
										element={
											<Navigate to="/dashboard" replace />
										}
									/>
									<Route
										path="/dashboard"
										element={ <Dashboard /> }
									/>
									<Route
										path="/entities"
										element={ <EntityManager /> }
									/>
									<Route
										path="/entities/:id"
										element={ <EntityManager /> }
									/>
									<Route
										path="/settings"
										element={ <Settings /> }
									/>
									<Route
										path="/logs"
										element={ <ActivityLog /> }
									/>
									<Route
										path="/kb/*"
										element={
											<KnowledgeBase
												vibeAiData={ vibeAiData }
											/>
										}
									/>
									{ /* Catch-all redirect */ }
									<Route
										path="*"
										element={
											<Navigate to="/dashboard" replace />
										}
									/>
								</Routes>
							</Suspense>
						</main>
					</div>
				</div>
			</HashRouter>
		</QueryClientProvider>
	);
}

// Export vibeAiData for use in other modules
export { vibeAiData };
