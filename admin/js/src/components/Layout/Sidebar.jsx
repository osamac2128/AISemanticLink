/**
 * Sidebar Component
 *
 * Navigation sidebar for the admin interface.
 */

import { useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';

/**
 * Navigation items configuration.
 */
const NAV_ITEMS = [
	{
		path: '/dashboard',
		label: 'Dashboard',
		icon: (
			<svg
				className="w-5 h-5"
				fill="none"
				stroke="currentColor"
				viewBox="0 0 24 24"
			>
				<path
					strokeLinecap="round"
					strokeLinejoin="round"
					strokeWidth={ 2 }
					d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"
				/>
			</svg>
		),
	},
	{
		path: '/entities',
		label: 'Entities',
		icon: (
			<svg
				className="w-5 h-5"
				fill="none"
				stroke="currentColor"
				viewBox="0 0 24 24"
			>
				<path
					strokeLinecap="round"
					strokeLinejoin="round"
					strokeWidth={ 2 }
					d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"
				/>
			</svg>
		),
	},
	{
		path: '/kb',
		label: 'Knowledge Base',
		icon: (
			<svg
				className="w-5 h-5"
				fill="none"
				stroke="currentColor"
				viewBox="0 0 24 24"
			>
				<path
					strokeLinecap="round"
					strokeLinejoin="round"
					strokeWidth={ 2 }
					d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"
				/>
			</svg>
		),
		children: [
			{ name: 'Overview', path: '/kb' },
			{ name: 'Documents', path: '/kb/documents' },
			{ name: 'Test Search', path: '/kb/search' },
			{ name: 'Settings', path: '/kb/settings' },
			{ name: 'Logs', path: '/kb/logs' },
		],
	},
	{
		path: '/settings',
		label: 'Settings',
		icon: (
			<svg
				className="w-5 h-5"
				fill="none"
				stroke="currentColor"
				viewBox="0 0 24 24"
			>
				<path
					strokeLinecap="round"
					strokeLinejoin="round"
					strokeWidth={ 2 }
					d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
				/>
				<path
					strokeLinecap="round"
					strokeLinejoin="round"
					strokeWidth={ 2 }
					d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
				/>
			</svg>
		),
	},
	{
		path: '/logs',
		label: 'Logs',
		icon: (
			<svg
				className="w-5 h-5"
				fill="none"
				stroke="currentColor"
				viewBox="0 0 24 24"
			>
				<path
					strokeLinecap="round"
					strokeLinejoin="round"
					strokeWidth={ 2 }
					d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
				/>
			</svg>
		),
	},
];

/**
 * Sidebar component.
 *
 * @param {Object}  props           - Component props.
 * @param {boolean} props.collapsed - Whether sidebar is collapsed.
 * @param {string}  props.basePath  - Base path for navigation.
 * @return {JSX.Element} Sidebar element.
 */
export default function Sidebar( { collapsed } ) {
	const location = useLocation();
	const [ kbExpanded, setKbExpanded ] = useState( false );

	const isItemActive = ( item ) => {
		if ( location.pathname === item.path ) return true;
		if (
			item.path !== '/dashboard' &&
			location.pathname.startsWith( item.path )
		)
			return true;
		// Check children paths too
		if ( item.children ) {
			return item.children.some( ( child ) =>
				location.pathname.startsWith( child.path )
			);
		}
		return false;
	};

	return (
		<aside
			className={ `
        fixed left-0 top-14 bottom-0
        bg-white border-r border-slate-200
        transition-all duration-300 z-20
        ${ collapsed ? 'w-16' : 'w-56' }
      ` }
		>
			<nav className="p-3 space-y-1">
				{ NAV_ITEMS.map( ( item ) => {
					const isActive = isItemActive( item );
					const hasChildren = item.children && item.children.length > 0;

					// Items with children: render as button + sub-items
					if ( hasChildren ) {
						return (
							<li
								key={ item.path }
								className="relative group list-none"
							>
								{ /* Main item button */ }
								<button
									type="button"
									onClick={ () => {
										if ( collapsed ) return; // flyout handles nav
										setKbExpanded( ( prev ) => ! prev );
									} }
									className={ `
                    flex items-center gap-3 w-full px-3 py-2.5 rounded-lg
                    transition-colors duration-150
                    ${
						isActive
							? 'bg-wp-primary/10 text-wp-primary'
							: 'text-slate-600 hover:bg-slate-100 hover:text-slate-800'
					}
                  ` }
									title={ collapsed ? item.label : undefined }
								>
									<span
										className={ `flex-shrink-0 ${
											isActive ? 'text-wp-primary' : ''
										}` }
									>
										{ item.icon }
									</span>
									{ ! collapsed && (
										<>
											<span className="font-medium truncate flex-1 text-left">
												{ item.label }
											</span>
											<svg
												className={ `w-4 h-4 transition-transform duration-200 ${
													kbExpanded ? 'rotate-90' : ''
												}` }
												fill="none"
												stroke="currentColor"
												viewBox="0 0 24 24"
											>
												<path
													strokeLinecap="round"
													strokeLinejoin="round"
													strokeWidth={ 2 }
													d="M9 5l7 7-7 7"
												/>
											</svg>
										</>
									) }
								</button>

								{ /* Inline sub-items when expanded */ }
								{ ! collapsed && kbExpanded && (
									<div className="ml-6 mt-1 space-y-1 border-l-2 border-slate-200 pl-3">
										{ item.children.map( ( child ) => {
											const childActive =
												location.pathname === child.path;
											return (
												<NavLink
													key={ child.path }
													to={ child.path }
													className={ `
                          flex items-center gap-2 px-3 py-1.5 rounded-md
                          text-sm transition-colors duration-150
                          ${
								childActive
									? 'bg-wp-primary/10 text-wp-primary font-medium'
									: 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'
							}
                        ` }
												>
													{ child.name }
												</NavLink>
											);
										} ) }
									</div>
								) }

								{ /* Flyout menu when collapsed */ }
								{ collapsed && (
									<div className="absolute left-full top-0 ml-2 bg-white border border-gray-200 rounded-lg shadow-lg py-2 min-w-[200px] z-50 hidden group-hover:block">
										<div className="px-4 py-1.5 text-xs font-semibold text-slate-400 uppercase tracking-wider">
											{ item.label }
										</div>
										{ item.children.map( ( child ) => {
											const childActive =
												location.pathname === child.path;
											return (
												<NavLink
													key={ child.path }
													to={ child.path }
													className={ `
                          flex items-center gap-2 px-4 py-2 text-sm
                          transition-colors duration-150
                          ${
								childActive
									? 'bg-wp-primary/10 text-wp-primary font-medium'
									: 'text-gray-700 hover:bg-gray-50'
							}
                        ` }
												>
													{ child.name }
												</NavLink>
											);
										} ) }
									</div>
								) }
							</li>
						);
					}

					// Regular items without children
					return (
						<NavLink
							key={ item.path }
							to={ item.path }
							className={ `
                flex items-center gap-3 px-3 py-2.5 rounded-lg
                transition-colors duration-150
                ${
					isActive
						? 'bg-wp-primary/10 text-wp-primary'
						: 'text-slate-600 hover:bg-slate-100 hover:text-slate-800'
				}
              ` }
							title={ collapsed ? item.label : undefined }
						>
							<span
								className={ `flex-shrink-0 ${
									isActive ? 'text-wp-primary' : ''
								}` }
							>
								{ item.icon }
							</span>
							{ ! collapsed && (
								<span className="font-medium truncate">
									{ item.label }
								</span>
							) }
						</NavLink>
					);
				} ) }
			</nav>

			{ /* Bottom section */ }
			{ ! collapsed && (
				<div className="absolute bottom-0 left-0 right-0 p-4 border-t border-slate-200">
					<div className="text-xs text-slate-400">
						<p>
							AI Entity Index{ ' ' }
							{ window.vibeAiData?.version || '1.0.7' }
						</p>
						<p className="mt-1">Powered by Vibe AI</p>
					</div>
				</div>
			) }
		</aside>
	);
}
