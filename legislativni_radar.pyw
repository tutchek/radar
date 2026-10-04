# -*- coding: utf-8 -*-
"""
Legislativní radar – spouštěč bez okna konzole (dvojklik ve Windows).
Stejné jako „py odok_web.py“; všechny soubory musí ležet ve stejné složce.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import odok_web  # noqa: E402

odok_web.main()
