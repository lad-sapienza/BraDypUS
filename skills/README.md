# Skills for assistants

Ready-made [Claude Code](https://claude.com/claude-code) skills for working with BraDypUS
from **other** projects.

| Skill | Use it to |
|---|---|
| [`bradypus-bootstrap`](bradypus-bootstrap/SKILL.md) | judge whether a dataset fits BraDypUS, design its schema, create an application and load the data with an assistant |

Download the ready-made archive from <https://docs.bdus.lad-sapienza.it/bradypus-bootstrap.zip> (rebuilt with every documentation deploy, so it always matches this folder), or copy the folder from the repository. Install one for all your projects by copying its folder:

```bash
cp -R skills/bradypus-bootstrap ~/.claude/skills/
```

or into a single project's `.claude/skills/`. For assistants that cannot load skills, the
same knowledge is reachable from <https://docs.bdus.lad-sapienza.it/llms.txt>.
