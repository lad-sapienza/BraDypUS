# Skills for assistants

Ready-made [Claude Code](https://claude.com/claude-code) skills for working with BraDypUS
from **other** projects.

| Skill | Use it to |
|---|---|
| [`bradypus-bootstrap`](bradypus-bootstrap/SKILL.md) | judge whether a dataset fits BraDypUS, design its schema, create an application and load the data with an assistant |

Install one for all your projects by copying its folder:

```bash
cp -R skills/bradypus-bootstrap ~/.claude/skills/
```

or into a single project's `.claude/skills/`. For assistants that cannot load skills, the
same knowledge is reachable from <https://docs.bdus.lad-sapienza.it/llms.txt>.
