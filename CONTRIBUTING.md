# Contributing to AI Entity Index

Thank you for your interest in contributing. This guide covers setup, code style, testing, and the pull request process.

## Development Setup

### Prerequisites

| Tool | Version |
|------|---------|
| PHP | 8.1+ |
| Node.js | 18+ |
| Composer | 2.x |
| MySQL | 8.0+ / MariaDB 10.6+ |
| WordPress | 6.0+ (development instance) |

### Setup

```bash
# Clone the repository
git clone https://github.com/your-repo/ai-entity-index.git
cd ai-entity-index

# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Start development build (watches for changes)
npm start
```

## Code Style

### PHP

- Follow **PSR-12** coding standard
- All files must declare strict types: `declare(strict_types=1);`
- Namespace all classes under `Vibe\AIIndex\*`
- Use type declarations for all function parameters and return types
- Run `./vendor/bin/phpcs` to check style (if configured)

### JavaScript / JSX

- Linting via **ESLint** using `@wordpress/scripts` config
- Formatting via **Prettier**
- Run `npm run lint` to check
- Run `npm run lint:fix` to auto-fix

### CSS

- Use **Tailwind CSS** utility classes
- All admin styles are scoped to `#vibe-ai-admin`
- Avoid custom CSS unless a Tailwind utility is insufficient

## Project Structure

```
ai-entity-index/
├── ai-entity-index.php          # Main plugin entry point
├── includes/                    # PHP classes (Plugin, Pipeline, Services, REST, etc.)
├── admin/js/src/                # React admin UI source
│   ├── App.jsx                  # Root component + routing
│   ├── api/                     # API client layer
│   └── components/              # Feature components
├── tests/                       # PHPUnit tests
├── docs/                        # Documentation
├── composer.json                # PHP dependencies
└── package.json                 # Node dependencies
```

## Making Changes

1. **Create a feature branch** from `main`:
   ```bash
   git checkout -b feat/your-feature-name
   ```

2. **Make your changes** with accompanying tests

3. **Run linters and tests** before submitting:
   ```bash
   ./vendor/bin/phpunit
   npm run lint
   ```

4. **Update documentation** for any behavior changes (see below)

5. **Commit** using conventional commit format (see below)

## Testing Requirements

- Run `./vendor/bin/phpunit` for the PHP test suite
- Run `npm run lint` for JavaScript/JSX linting
- **Add tests** for any new functionality
- **All existing tests must pass** — no regressions

## Documentation Updates

When making behavior changes, update the relevant documentation:

- Update the relevant file(s) in `docs/` for any behavior changes
- Add **Source refs** with file and line anchors (e.g., `includes/REST/RestController.php:127`)
- Update `docs/index.md` if navigation structure changed
- Record any intentional code/docs mismatches in `docs/changelog/implementation-drift.md`

## Commit Messages

Use [Conventional Commits](https://www.conventionalcommits.org/) format:

```
type(scope): short description

Optional longer description
```

| Type | Use For |
|------|---------|
| `feat` | New feature |
| `fix` | Bug fix |
| `docs` | Documentation changes |
| `refactor` | Code restructuring without behavior change |
| `test` | Adding or updating tests |
| `chore` | Build, tooling, or dependency changes |

Examples:

```
feat(kb): add semantic search endpoint
fix(entities): correct alias resolution for multi-word names
docs(api): update entity endpoints table
refactor(pipeline): extract batch processing into BatchSizeManager
```

## Pull Request Process

1. Ensure all tests pass (`./vendor/bin/phpunit` and `npm run lint`)
2. Ensure documentation is updated for any behavior changes
3. Verify no secrets, API keys, or credentials are committed
4. Open a pull request with a clear description of the change and motivation
5. Link any related issues
