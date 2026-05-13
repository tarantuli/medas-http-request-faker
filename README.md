# Create a new project using this template

- Create a new repository here by clicking "Use this template" above.

- In PhpStorm, simply get the new repo from version control
- Otherwise:

  - Create a new empty PHP project locally.
  - Initialize git, add the new repository as a remote, and pull and switch to dev:

  ```
  git init
  git remote add origin <url>
  git pull origin dev
  git checkout dev
  ```

- Replace all occurrences of `placeholder` in the code _and_ the file names by the package name.
- Run `composer update`.
- The README.md file will be a copy of this file, delete everything from the start up to the next header.

# medas-placeholder

Part of the [Medas framework](https://github.com/tarantuli/medas-core).
