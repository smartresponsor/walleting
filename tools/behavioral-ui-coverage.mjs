import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();

function filesUnder(relativeDirectory, extension = null) {
  const directory = path.join(root, relativeDirectory);
  if (!fs.existsSync(directory)) {
    return [];
  }

  const result = [];
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const relative = path.join(relativeDirectory, entry.name);
    if (entry.isDirectory()) {
      result.push(...filesUnder(relative, extension));
      continue;
    }
    if (!extension || entry.name.endsWith(extension)) {
      result.push(relative.replaceAll('\\', '/'));
    }
  }

  return result.sort();
}

const controllerFiles = filesUnder('src/Controller', '.php');
const sourcePhpFiles = filesUnder('src', '.php');
const routeAttributeFiles = sourcePhpFiles.filter((relative) =>
  fs.readFileSync(path.join(root, relative), 'utf8').includes('#[Route'),
);

const routeConfigFiles = [
  ...filesUnder('config/routes', '.yaml'),
  ...filesUnder('config/routes', '.yml'),
  ...['config/routes.yaml', 'config/routes.yml'].filter((relative) =>
    fs.existsSync(path.join(root, relative)),
  ),
];
const explicitRouteConfigFiles = routeConfigFiles.filter((relative) => {
  const content = fs.readFileSync(path.join(root, relative), 'utf8');
  return /^\s*path\s*:/m.test(content);
});

const interactiveTemplates = filesUnder('templates', '.twig').filter(
  (relative) => relative !== 'templates/base.html.twig',
);

const ownedSurfaces = [
  ...controllerFiles,
  ...routeAttributeFiles,
  ...explicitRouteConfigFiles,
  ...interactiveTemplates,
];

if (ownedSurfaces.length > 0) {
  throw new Error(
    'Walleting now owns application/UI surfaces; declare explicit behavioral coverage inventory before regenerating evidence: ' +
      ownedSurfaces.join(', '),
  );
}

const evidence = {
  schema: 'behavioral-ui-coverage-v2',
  generatedAt: new Date().toISOString(),
  producer: {
    kind: 'repository_script',
    script: 'coverage:behavioral-ui',
  },
  dimensions: {
    functional: { eligible: [], covered: [] },
    behavioral: { eligible: [], covered: [] },
    ui: { eligible: [], covered: [] },
    critical: { eligible: [], covered: [] },
  },
};

const outputDirectory = path.join(root, 'var', 'coverage');
fs.mkdirSync(outputDirectory, { recursive: true });
fs.writeFileSync(
  path.join(outputDirectory, 'behavioral-ui.json'),
  JSON.stringify(evidence, null, 2) + '\n',
  'utf8',
);

process.stdout.write(
  'Behavioral/UI coverage evidence generated: Walleting owns no application/UI surfaces.\n',
);
