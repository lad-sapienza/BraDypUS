# Updating BraDypUS

If you installed BraDypUS via **terminal**, updating is straightforward (change the path to the current installation):

```bash
cd /path/to/current/installation/BraDypUS && \
git fetch origin dev && \
git reset --hard FETCH_HEAD && \
git clean -df 
```
If you installed BraDypUS **manually**:

1. Remove every file and folder inside the BraDypUS folder **except the direcory projects**
2. Download a fresh copy of the software
3. Move files from the fresh folder to the previous one
4. Make sure that the **projects** folder never gets touched